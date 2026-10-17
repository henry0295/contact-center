<?php

namespace App\Middleware;

use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Restringe un grupo de rutas a determinados roles. Se aplica DESPUÉS del
 * JwtMiddleware, que es quien inyecta el atributo 'user'.
 *
 * Antes, cualquier usuario autenticado podía crear y eliminar usuarios.
 */
class RoleMiddleware
{
    /** @var string[] */
    private array $allowed;

    public function __construct(string ...$allowed)
    {
        $this->allowed = array_map('strtolower', $allowed);
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute('user');
        $role = strtolower((string) ($user['role'] ?? ''));

        if (!in_array($role, $this->allowed, true)) {
            Logger::audit()->warning('Acceso denegado por rol', array_merge(
                Logger::requestContext(),
                ['email' => $user['email'] ?? null, 'rol' => $role, 'requiere' => $this->allowed]
            ));

            $response = new SlimResponse();
            $response->getBody()->write(json_encode([
                'error'   => 'Acceso denegado',
                'message' => 'Esta acción requiere el rol: ' . implode(' o ', $this->allowed),
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        return $handler->handle($request);
    }
}
