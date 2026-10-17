<?php

namespace App\Support;

/**
 * Utilidades de la petición HTTP compartidas por logging y rate limiting.
 */
class Request
{
    /**
     * IP del cliente. Si la aplicación está detrás de un proxy inverso (Nginx,
     * balanceador), se puede confiar en X-Forwarded-For activando
     * TRUST_PROXY=true en el .env.
     *
     * Por defecto NO se confía: un atacante podría falsear la cabecera y evadir
     * el límite de intentos de login.
     */
    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $trustProxy = filter_var($_ENV['TRUST_PROXY'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$trustProxy) {
            return $remote;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded === '') {
            return $remote;
        }

        // El primer valor es el cliente original.
        $first = trim(explode(',', $forwarded)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $remote;
    }
}
