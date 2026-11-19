<?php

namespace App\Support;

/**
 * Usuario autenticado de la petición en curso (lo fija JwtMiddleware). Sirve
 * para registrar quién creó una campaña sin pasar el usuario por cada capa, y
 * para limitar los reportes: un usuario que no es administrador solo ve sus
 * propios envíos. En consola y en el dispatcher queda vacío (ve todo).
 */
final class CurrentUser
{
    private static ?array $user = null;

    public static function set(?array $user): void
    {
        self::$user = $user;
    }

    public static function email(): ?string
    {
        $email = self::$user['email'] ?? null;

        return is_string($email) && $email !== '' ? mb_substr($email, 0, 255) : null;
    }

    public static function isAdmin(): bool
    {
        return strtolower((string) (self::$user['role'] ?? '')) === 'admin';
    }

    /**
     * Email al que se limitan los reportes y campañas (campaigns.created_by),
     * o null si se ve todo (administrador, consola, dispatcher). Un usuario sin
     * email devuelve '' para no ver nada en vez de verlo todo.
     */
    public static function ownerScope(): ?string
    {
        if (self::$user === null || self::isAdmin()) {
            return null;
        }

        return self::email() ?? '';
    }
}
