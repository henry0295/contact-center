<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class LoginAttemptRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function record(string $ip, ?string $email, bool $successful): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (ip, email, successful) VALUES (:ip, :email, :ok)'
        );
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':ok', $successful ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function markSuccessful(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE login_attempts SET successful = 1 WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Intentos fallidos desde una IP en los últimos N segundos. */
    public function failuresByIp(string $ip, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip = :ip AND successful = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL :secs SECOND)'
        );
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->bindValue(':secs', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** Intentos fallidos contra una cuenta en los últimos N segundos. */
    public function failuresByEmail(string $email, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND successful = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL :secs SECOND)'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':secs', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Segundos que faltan para que el intento fallido más antiguo de la ventana
     * caduque; sirve para informar al cliente cuánto debe esperar.
     */
    public function secondsUntilUnlock(string $ip, ?string $email, int $windowSeconds): int
    {
        // El cálculo se hace ENTERO en SQL. Hacerlo en PHP comparaba el reloj de
        // PHP con marcas de tiempo escritas por MySQL, y cualquier diferencia de
        // zona horaria entre ambos daba un tiempo de espera disparatado.
        $sql = 'SELECT GREATEST(0, :secs2 - TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()))
                FROM login_attempts
                WHERE successful = 0
                  AND created_at > DATE_SUB(NOW(), INTERVAL :secs SECOND)
                  AND (ip = :ip';
        if ($email !== null) {
            $sql .= ' OR email = :email';
        }
        $sql .= ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':secs', $windowSeconds, PDO::PARAM_INT);
        $stmt->bindValue(':secs2', $windowSeconds, PDO::PARAM_INT);
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        if ($email !== null) {
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        }
        $stmt->execute();

        $remaining = $stmt->fetchColumn();
        if ($remaining === null || $remaining === false) {
            return 0;
        }

        return max(1, (int) $remaining);
    }

    /** Limpia el historial de una cuenta tras un login correcto. */
    public function clearForEmail(string $email): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND successful = 0');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
    }

    /** Poda registros antiguos para que la tabla no crezca sin control. */
    public function prune(int $olderThanDays = 7): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt->bindValue(':days', $olderThanDays, PDO::PARAM_INT);
        $stmt->execute();
    }
}
