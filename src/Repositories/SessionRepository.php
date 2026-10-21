<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

/**
 * Sesiones emitidas. Un JWT es autocontenido y no se puede invalidar por sí
 * solo; guardar su identificador (jti) permite cerrar sesión de verdad y
 * revocar accesos robados antes de que caduquen.
 */
class SessionRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function create(
        string $jti,
        int $userId,
        ?string $refreshTokenHash,
        int $expiresAtTs,
        ?int $refreshExpiresAtTs,
        ?string $ip,
        ?string $userAgent
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (jti, user_id, refresh_token_hash, expires_at, refresh_expires_at, ip, user_agent)
             VALUES (:jti, :user_id, :refresh, :expires, :refresh_expires, :ip, :ua)'
        );
        $stmt->bindValue(':jti', $jti, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':refresh', $refreshTokenHash, $refreshTokenHash === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':expires', date('Y-m-d H:i:s', $expiresAtTs), PDO::PARAM_STR);
        $stmt->bindValue(
            ':refresh_expires',
            $refreshExpiresAtTs === null ? null : date('Y-m-d H:i:s', $refreshExpiresAtTs),
            $refreshExpiresAtTs === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $stmt->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':ua', $userAgent === null ? null : mb_substr($userAgent, 0, 255), $userAgent === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * ¿Sigue vigente esta sesión? Se exige que exista y no esté revocada: así,
     * si se borra el historial de sesiones, los tokens dejan de valer.
     */
    public function isActive(string $jti): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM sessions WHERE jti = :jti AND revoked = 0 AND expires_at > NOW() LIMIT 1'
        );
        $stmt->bindValue(':jti', $jti, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rol ACTUAL del usuario de una sesión activa (null si la sesión no vale o
     * el usuario ya no existe). Se usa en vez del rol del JWT para que bajar
     * de rol o borrar a un usuario surta efecto de inmediato.
     */
    public function activeRole(string $jti): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.role FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.jti = :jti AND s.revoked = 0 AND s.expires_at > NOW() LIMIT 1'
        );
        $stmt->bindValue(':jti', $jti, PDO::PARAM_STR);
        $stmt->execute();
        $role = $stmt->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    public function revoke(string $jti): bool
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET revoked = 1 WHERE jti = :jti AND revoked = 0');
        $stmt->bindValue(':jti', $jti, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** Cierra todas las sesiones de un usuario (cambio de contraseña, baja...). */
    public function revokeAllForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET revoked = 1 WHERE user_id = :id AND revoked = 0');
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** Busca una sesión vigente por el hash de su refresh token. */
    public function findByRefreshHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sessions
             WHERE refresh_token_hash = :hash AND revoked = 0 AND refresh_expires_at > NOW()
             LIMIT 1'
        );
        $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, jti, revoked, expires_at, ip, user_agent, created_at
             FROM sessions WHERE user_id = :id ORDER BY created_at DESC LIMIT 50'
        );
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Borra sesiones caducadas; se llama desde el dispatcher. */
    public function prune(): int
    {
        $stmt = $this->pdo->query(
            'DELETE FROM sessions
             WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND (refresh_expires_at IS NULL OR refresh_expires_at < NOW())'
        );

        return $stmt->rowCount();
    }
}
