<?php

namespace App\Support;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;

/**
 * Fábrica de loggers de la aplicación.
 *
 * Canales:
 *   - app   : arranque, errores no capturados, autenticación, acciones de admin.
 *   - mail  : envíos de email y SMS (uno por destinatario), con su resultado.
 *   - audit : acciones sensibles (alta/baja de usuarios, cambios de secreto).
 *
 * Los archivos rotan a diario en storage/logs y se conservan 14 días. En
 * desarrollo también se escribe en la salida de error para verlo en consola.
 */
class Logger
{
    /** @var array<string,LoggerInterface> */
    private static array $channels = [];

    private const RETAIN_DAYS = 14;

    public static function get(string $channel = 'app'): LoggerInterface
    {
        if (isset(self::$channels[$channel])) {
            return self::$channels[$channel];
        }

        $logger = new Monolog($channel);
        $dir = self::directory();

        $level = self::isProduction() ? Level::Info : Level::Debug;

        // Formato legible, con contexto en una sola línea.
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,  // permite saltos de línea en el mensaje
            true   // ignora contexto vacío
        );

        if ($dir !== null) {
            $handler = new RotatingFileHandler($dir . '/' . $channel . '.log', self::RETAIN_DAYS, $level);
            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);
        }

        // En desarrollo, además por stderr (visible en el servidor embebido).
        if (!self::isProduction()) {
            $stream = new StreamHandler('php://stderr', Level::Warning);
            $stream->setFormatter($formatter);
            $logger->pushHandler($stream);
        }

        self::$channels[$channel] = $logger;

        return $logger;
    }

    /** Atajos de uso frecuente. */
    public static function app(): LoggerInterface
    {
        return self::get('app');
    }

    public static function mail(): LoggerInterface
    {
        return self::get('mail');
    }

    public static function audit(): LoggerInterface
    {
        return self::get('audit');
    }

    /**
     * Carpeta de logs; null si no se puede crear (el logging nunca debe tumbar
     * la aplicación por un problema de permisos).
     */
    private static function directory(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';

        if (is_dir($dir)) {
            return is_writable($dir) ? $dir : null;
        }

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    private static function isProduction(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'development') === 'production';
    }

    /** Datos de la petición útiles en todos los registros. */
    public static function requestContext(): array
    {
        return [
            'ip'     => Request::clientIp(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri'    => $_SERVER['REQUEST_URI'] ?? '-',
        ];
    }
}
