<?php

namespace App\Controllers;

use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Helper compartido para respuestas JSON, con el mismo contrato {error,message}
 * que ya usan los controladores de verificación.
 */
trait JsonResponseTrait
{
    protected function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Error inesperado (500). El detalle técnico (que puede incluir SQL, rutas
     * o datos del proveedor) va solo al log; en producción el usuario recibe
     * un mensaje genérico con una referencia para buscarlo en el log.
     */
    protected function internalError(Response $response, string $publicMessage, \Throwable $e, int $status = 500): Response
    {
        $ref = substr(bin2hex(random_bytes(4)), 0, 8);
        Logger::app()->error($publicMessage, array_merge(Logger::requestContext(), [
            'ref'       => $ref,
            'exception' => get_class($e),
            'error'     => $e->getMessage(),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]));

        $production = ($_ENV['APP_ENV'] ?? 'development') === 'production';

        return $this->jsonResponse($response, [
            'error'   => $publicMessage,
            'message' => $production ? "Error interno (referencia {$ref}). Si persiste, contacta a soporte." : $e->getMessage(),
            'ref'     => $ref,
        ], $status);
    }
}
