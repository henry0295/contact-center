<?php

namespace App\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Emite y valida JWT firmados con HS256 usando JWT_SECRET del .env.
 *
 * Cada token lleva un identificador único (jti) que se registra en la tabla
 * `sessions`. Eso es lo que permite cerrar sesión de verdad: un JWT por sí
 * solo no se puede invalidar antes de que caduque.
 */
class AuthService
{
    private const ALG = 'HS256';

    /** Validez del refresh token (14 días por defecto). */
    public const REFRESH_TTL = 1209600;

    private static function secret(): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET no está configurado en el .env');
        }
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET es demasiado corto; genera uno con: composer secrets');
        }

        return $secret;
    }

    private static function ttl(): int
    {
        return (int) ($_ENV['JWT_EXPIRY'] ?? 86400);
    }

    public static function refreshTtl(): int
    {
        return (int) ($_ENV['JWT_REFRESH_EXPIRY'] ?? self::REFRESH_TTL);
    }

    /**
     * @param array $user fila de la tabla users (id, name, email, role)
     * @return array{token:string, expiresIn:int, jti:string, expiresAt:int}
     */
    public static function issue(array $user): array
    {
        $now = time();
        $ttl = self::ttl();
        $jti = bin2hex(random_bytes(16));

        $payload = [
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + $ttl,
            'jti'   => $jti,
            'sub'   => (int) $user['id'],
            'email' => $user['email'],
            'name'  => $user['name'],
            'role'  => $user['role'],
        ];

        return [
            'token'     => JWT::encode($payload, self::secret(), self::ALG),
            'expiresIn' => $ttl,
            'jti'       => $jti,
            'expiresAt' => $now + $ttl,
        ];
    }

    public static function decode(string $token): object
    {
        return JWT::decode($token, new Key(self::secret(), self::ALG));
    }

    /** Genera un refresh token opaco y su hash para guardar en base de datos. */
    public static function newRefreshToken(): array
    {
        $plain = bin2hex(random_bytes(32));

        return ['plain' => $plain, 'hash' => self::hashRefreshToken($plain)];
    }

    /**
     * El refresh token se guarda hasheado: si alguien lee la base de datos, no
     * puede usar los tokens almacenados. SHA-256 basta porque es un valor
     * aleatorio de 256 bits (no una contraseña adivinable).
     */
    public static function hashRefreshToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
