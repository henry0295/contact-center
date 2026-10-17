<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

/**
 * Lista de supresión: direcciones que rebotaron de forma permanente o que se
 * quejaron (marcaron el correo como spam).
 *
 * Seguir enviando a estas direcciones deteriora la reputación de envío de la
 * cuenta en AWS y puede acabar en la suspensión del servicio, así que se
 * bloquean antes de llamar a SES.
 */
class SuppressionRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function add(string $email, string $reason, ?string $detail = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO suppressions (email, reason, detail)
             VALUES (:email, :reason, :detail)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), detail = VALUES(detail)'
        );
        $stmt->bindValue(':email', strtolower(trim($email)), PDO::PARAM_STR);
        $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
        $stmt->bindValue(
            ':detail',
            $detail === null ? null : mb_substr($detail, 0, 500),
            $detail === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $stmt->execute();
    }

    public function isSuppressed(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM suppressions WHERE email = :email LIMIT 1');
        $stmt->bindValue(':email', strtolower(trim($email)), PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Filtra de una lista las direcciones suprimidas, en una sola consulta.
     *
     * @param  string[] $emails
     * @return array{allowed:string[], blocked:string[]}
     */
    public function partition(array $emails): array
    {
        if (empty($emails)) {
            return ['allowed' => [], 'blocked' => []];
        }

        $normalized = array_values(array_unique(array_map(
            static fn (string $e): string => strtolower(trim($e)),
            $emails
        )));

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $stmt = $this->pdo->prepare("SELECT email FROM suppressions WHERE email IN ({$placeholders})");
        $stmt->execute($normalized);

        $blocked = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $blockedSet = array_flip($blocked);

        $allowed = [];
        foreach ($emails as $original) {
            if (!isset($blockedSet[strtolower(trim($original))])) {
                $allowed[] = $original;
            }
        }

        return ['allowed' => $allowed, 'blocked' => $blocked];
    }

    public function remove(string $email): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM suppressions WHERE email = :email');
        $stmt->bindValue(':email', strtolower(trim($email)), PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** @return array{items:array, total:int} */
    public function paginate(int $limit, int $offset): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM suppressions')->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM suppressions ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Totales por motivo, para el panel de Reportes. */
    public function countsByReason(): array
    {
        $rows = $this->pdo->query('SELECT reason, COUNT(*) AS total FROM suppressions GROUP BY reason')->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['reason']] = (int) $r['total'];
        }

        return $out;
    }
}
