<?php

namespace App\Support;

/**
 * Usuario autenticado de la petición en curso (lo fija JwtMiddleware). Sirve
 * para registrar quién creó una campaña sin pasar el usuario por cada capa.
 * En consola y en el dispatcher queda vacío.
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
}
