<?php

namespace App\Middleware;

use App\Repositories\SessionRepository;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Protege las rutas /api: exige un JWT válido en el header Authorization.
 *
 * Además de la firma se comprueba que la sesión (jti) siga activa en base de
 * datos: eso es lo que permite que el cierre de sesión y la revocación surtan
 * efecto de inmediato, algo que un JWT por sí solo no puede hacer.
 *
 * Inyecta en la petición los atributos 'user' y 'jti'.
 */
class JwtMiddleware
{
    private SessionRepository $sessions;

    public function __construct(?SessionRepository $sessions = null)
    {
        $this->sessions = $sessions ?? new SessionRepository();
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return $this->unauthorized('Token no proporcionado');
        }

        try {
            $decoded = AuthService::decode($matches[1]);
        } catch (\Throwable $e) {
            return $this->unauthorized('Token inválido o expirado');
        }

        $jti = $decoded->jti ?? null;
        if (!is_string($jti) || $jti === '') {
            return $this->unauthorized('Token sin identificador de sesión');
        }

        try {
            if (!$this->sessions->isActive($jti)) {
                return $this->unauthorized('La sesión se cerró o fue revocada');
            }
        } catch (\Throwable $e) {
            // Si la base de datos no responde no se puede validar la sesión:
            // se rechaza en lugar de abrir la puerta.
            return $this->unauthorized('No se pudo validar la sesión');
        }

        $request = $request
            ->withAttribute('jti', $jti)
            ->withAttribute('user', [
                'id'    => (int) $decoded->sub,
                'email' => $decoded->email ?? null,
                'name'  => $decoded->name ?? null,
                'role'  => $decoded->role ?? null,
            ]);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => 'No autorizado', 'message' => $message]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}
