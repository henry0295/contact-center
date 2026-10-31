<?php

namespace App\Support;

/**
 * Helper central para credenciales AWS. Soporta claves permanentes (AKIA...) y
 * temporales de STS (ASIA...), que además requieren AWS_SESSION_TOKEN. Si el
 * token está presente en el .env se incluye automáticamente.
 */
class Aws
{
    public static function credentials(): array
    {
        $creds = [
            'key'    => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
            'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
        ];

        if (!empty($_ENV['AWS_SESSION_TOKEN'])) {
            $creds['token'] = $_ENV['AWS_SESSION_TOKEN'];
        }

        return $creds;
    }

    public static function region(): string
    {
        return $_ENV['AWS_REGION'] ?? 'us-east-1';
    }

    /**
     * Configuración común de los clientes AWS.
     *
     * El modo de reintentos "adaptive" añade limitación de velocidad en el
     * propio cliente: es lo que recomienda AWS para no chocar con el límite de
     * envíos por segundo de SES (14/s en la cuenta de Vozip) durante los envíos
     * masivos. Sin esto, un bucle de envíos devolvía errores de throttling.
     */
    public static function clientConfig(): array
    {
        return [
            'version'     => 'latest',
            'region'      => self::region(),
            'credentials' => self::credentials(),
            'retries'     => [
                'mode'         => 'adaptive',
                'max_attempts' => 5,
            ],
        ];
    }
}
