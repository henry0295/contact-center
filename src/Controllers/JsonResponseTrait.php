<?php

namespace App\Controllers;

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
}
