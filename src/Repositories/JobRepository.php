<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

/**
 * Cola de trabajos respaldada en la base de datos.
 *
 * Evita depender de Redis o SQS: para el volumen de esta aplicación, una tabla
 * con reserva atómica es suficiente y no añade infraestructura. El worker vive
 * en bin/dispatch-campaigns.php.
 */
class JobRepository
{
    public const TYPE_CAMPAIGN = 'campaign';
    /** Consulta el estado de llamadas de voz ya originadas y las cierra. */
    public const TYPE_CALL_STATUS = 'call_status';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function enqueue(string $type, array $payload = [], int $maxAttempts = 3): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (type, payload, max_attempts) VALUES (:type, :payload, :max)'
        );
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':payload', json_encode($payload), PDO::PARAM_STR);
        $stmt->bindValue(':max', $maxAttempts, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Reserva atómicamente hasta $limit trabajos disponibles.
     *
     * Se hace en dos pasos (UPDATE marcando con un token propio y luego SELECT
     * por ese token) porque MariaDB 10.4 no soporta SKIP LOCKED. Así dos
     * procesos nunca se llevan el mismo trabajo.
     *
     * @return array<int,array>
     */
    public function claim(int $limit = 1): array
    {
        $token = bin2hex(random_bytes(16));

        $update = $this->pdo->prepare(
            "UPDATE jobs
                SET status = 'Reserved', reserved_at = NOW(), worker = :token, attempts = attempts + 1
              WHERE status = 'Pending' AND available_at <= NOW()
              ORDER BY id ASC
              LIMIT {$limit}"
        );
        $update->bindValue(':token', $token, PDO::PARAM_STR);
        $update->execute();

        if ($update->rowCount() === 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE worker = :token AND status = 'Reserved' ORDER BY id ASC");
        $stmt->bindValue(':token', $token, PDO::PARAM_STR);
        $stmt->execute();

        return array_map(static function (array $row): array {
            $row['payload'] = json_decode($row['payload'] ?? '{}', true) ?: [];

            return $row;
        }, $stmt->fetchAll());
    }

    public function complete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE jobs SET status = 'Completed', finished_at = NOW(), worker = NULL, last_error = NULL WHERE id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Marca el fallo. Si quedan intentos, vuelve a la cola con espera
     * exponencial; si no, queda como fallido definitivo.
     */
    public function fail(int $id, string $error): void
    {
        $job = $this->find($id);
        $attempts = (int) ($job['attempts'] ?? 1);
        $max = (int) ($job['max_attempts'] ?? 3);

        if ($attempts < $max) {
            $delay = min(3600, 60 * (2 ** ($attempts - 1)));
            $stmt = $this->pdo->prepare(
                "UPDATE jobs
                    SET status = 'Pending', worker = NULL, last_error = :err,
                        available_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
                  WHERE id = :id"
            );
            $stmt->bindValue(':delay', $delay, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE jobs SET status = 'Failed', worker = NULL, last_error = :err, finished_at = NOW() WHERE id = :id"
            );
        }

        $stmt->bindValue(':err', mb_substr($error, 0, 2000), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Devuelve a la cola los trabajos que quedaron reservados por un proceso
     * que murió (p.ej. si se reinició el servidor a mitad).
     */
    public function releaseStale(int $minutes = 30): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE jobs
                SET status = 'Pending', worker = NULL
              WHERE status = 'Reserved' AND reserved_at < DATE_SUB(NOW(), INTERVAL :mins MINUTE)"
        );
        $stmt->bindValue(':mins', $minutes, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function pendingCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'Pending'")->fetchColumn();
    }

    /** @return array{items:array, total:int} */
    public function paginate(int $limit, int $offset): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT * FROM jobs ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Poda trabajos terminados antiguos. */
    public function prune(int $days = 30): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM jobs
              WHERE status IN ('Completed','Failed')
                AND finished_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
