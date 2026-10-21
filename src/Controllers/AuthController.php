<?php

namespace App\Controllers;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Service\AuthService;
use App\Service\LoginThrottle;
use App\Support\Logger;
use App\Support\Request as RequestSupport;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    use JsonResponseTrait;

    private UserRepository $users;
    private SessionRepository $sessions;
    private LoginThrottle $throttle;

    public function __construct(
        ?UserRepository $users = null,
        ?SessionRepository $sessions = null,
        ?LoginThrottle $throttle = null
    ) {
        $this->users = $users ?? new UserRepository();
        $this->sessions = $sessions ?? new SessionRepository();
        $this->throttle = $throttle ?? new LoginThrottle();
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        // Normalizado: la búsqueda de usuarios no distingue mayúsculas, así que el
        // contador tampoco debe (si no, "Admin@" y "admin@" serían cuentas
        // distintas para el límite y se podría esquivar).
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $ip = RequestSupport::clientIp();

        if ($email === '' || $password === '') {
            return $this->jsonResponse($response, ['error' => 'Email y contraseña son obligatorios'], 400);
        }
        // bcrypt ignora a partir del byte 72; un tope evita además peticiones enormes.
        if (mb_strlen($email) > 255 || strlen($password) > 1024) {
            return $this->jsonResponse($response, ['error' => 'Credenciales inválidas'], 401);
        }

        // Freno a la fuerza bruta antes de tocar la base de datos de usuarios.
        $state = $this->throttle->check($ip, $email);
        if ($state['blocked']) {
            return $this->tooManyAttempts($response, $email, $state);
        }

        // El intento se registra ANTES de verificar la contraseña. Así, con
        // muchas peticiones en paralelo, todas cuentan: sin esto, varias pasaban
        // el check() a la vez y se superaba el límite.
        $attemptId = $this->throttle->begin($ip, $email);
        $state = $this->throttle->check($ip, $email, true);
        if ($state['blocked']) {
            return $this->tooManyAttempts($response, $email, $state);
        }

        $user = $this->users->findByEmail($email);
        // Se verifica siempre contra un hash (uno ficticio si el usuario no
        // existe): el tiempo de respuesta no revela qué correos están registrados.
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH) && $user !== null;
        if (!$valid) {
            // El intento ya quedó registrado como fallido en begin().
            Logger::app()->warning('Intento de login fallido', array_merge(
                Logger::requestContext(),
                ['email' => $email]
            ));

            $remaining = $this->throttle->remainingForEmail($email);
            $payload = ['error' => 'Credenciales inválidas'];
            if ($remaining > 0 && $remaining <= 2) {
                $payload['warning'] = $remaining === 1
                    ? 'Te queda 1 intento antes del bloqueo temporal.'
                    : "Te quedan {$remaining} intentos antes del bloqueo temporal.";
            }

            return $this->jsonResponse($response, $payload, 401);
        }

        $this->throttle->succeed($attemptId, $email);

        return $this->issueSession($response, $user, $request, 'Login correcto');
    }

    /** Hash bcrypt de una contraseña aleatoria, solo para igualar tiempos. */
    private const DUMMY_HASH = '$2y$10$GfLE/z1AR9tAytBmW4R58.257QPFc6x6x01P5bJrxVPqb6nzSBJFW';

    private function tooManyAttempts(Response $response, string $email, array $state): Response
    {
        Logger::app()->warning('Login bloqueado por demasiados intentos', array_merge(
            Logger::requestContext(),
            ['email' => $email, 'motivo' => $state['reason']]
        ));

        $minutes = (int) ceil($state['retryAfter'] / 60);

        return $this->jsonResponse($response, [
            'error'      => 'Demasiados intentos fallidos. Inténtalo de nuevo en '
                . ($minutes <= 1 ? 'un minuto' : "{$minutes} minutos") . '.',
            'retryAfter' => $state['retryAfter'],
        ], 429)->withHeader('Retry-After', (string) $state['retryAfter']);
    }

    /** Devuelve el usuario autenticado (lo inyecta el JwtMiddleware). */
    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        return $this->jsonResponse($response, ['user' => $user]);
    }

    /**
     * Cierre de sesión real: revoca el jti del token actual, de modo que deja
     * de valer aunque no haya caducado.
     */
    public function logout(Request $request, Response $response): Response
    {
        $jti = $request->getAttribute('jti');
        $user = $request->getAttribute('user');

        if (is_string($jti) && $jti !== '') {
            $this->sessions->revoke($jti);
        }

        Logger::app()->info('Cierre de sesión', array_merge(
            Logger::requestContext(),
            ['email' => $user['email'] ?? null]
        ));

        return $this->jsonResponse($response, ['success' => true, 'message' => 'Sesión cerrada']);
    }

    /** Cierra todas las sesiones del usuario en todos sus dispositivos. */
    public function logoutAll(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $count = $this->sessions->revokeAllForUser((int) $user['id']);

        Logger::app()->info('Cierre de todas las sesiones', array_merge(
            Logger::requestContext(),
            ['email' => $user['email'] ?? null, 'sesiones' => $count]
        ));

        return $this->jsonResponse($response, ['success' => true, 'revoked' => $count]);
    }

    /**
     * Canjea un refresh token por un nuevo token de acceso. Ruta pública: el
     * token de acceso puede haber caducado ya.
     *
     * Se rota el refresh token en cada uso (la sesión anterior se revoca), de
     * forma que un refresh token robado solo sirve una vez y el uso duplicado
     * queda invalidado.
     */
    public function refresh(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $plain = trim($data['refreshToken'] ?? '');

        if ($plain === '') {
            return $this->jsonResponse($response, ['error' => 'Falta el refresh token'], 400);
        }

        $session = $this->sessions->findByRefreshHash(AuthService::hashRefreshToken($plain));
        if ($session === null) {
            return $this->jsonResponse($response, ['error' => 'Refresh token inválido o expirado'], 401);
        }

        $user = $this->users->findById((int) $session['user_id']);
        if ($user === null) {
            return $this->jsonResponse($response, ['error' => 'El usuario ya no existe'], 401);
        }

        // Rotación: la sesión antigua queda revocada.
        $this->sessions->revoke($session['jti']);

        return $this->issueSession($response, $user, $request, 'Sesión renovada');
    }

    /** Cambio de contraseña del propio usuario autenticado. */
    public function changePassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $current = (string) ($data['currentPassword'] ?? '');
        $new = (string) ($data['newPassword'] ?? '');
        $authUser = $request->getAttribute('user');

        if ($current === '' || $new === '') {
            return $this->jsonResponse($response, ['error' => 'Debes indicar la contraseña actual y la nueva'], 400);
        }

        $error = self::validatePasswordStrength($new);
        if ($error !== null) {
            return $this->jsonResponse($response, ['error' => $error], 400);
        }

        $user = $this->users->findById((int) $authUser['id']);
        // Mismo límite que el login: con una sesión robada no se puede probar
        // contraseñas actuales sin freno.
        $ip = RequestSupport::clientIp();
        $throttleKey = mb_strtolower((string) ($user['email'] ?? ''));
        $state = $this->throttle->check($ip, $throttleKey);
        if ($state['blocked']) {
            return $this->tooManyAttempts($response, $throttleKey, $state);
        }
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            $this->throttle->recordFailure($ip, $throttleKey);
            Logger::app()->warning('Cambio de contraseña rechazado', array_merge(
                Logger::requestContext(),
                ['email' => $authUser['email'] ?? null]
            ));

            return $this->jsonResponse($response, ['error' => 'La contraseña actual no es correcta'], 401);
        }

        if (password_verify($new, $user['password_hash'])) {
            return $this->jsonResponse($response, ['error' => 'La nueva contraseña debe ser distinta de la actual'], 400);
        }

        $this->users->updatePassword((int) $user['id'], password_hash($new, PASSWORD_BCRYPT));

        // Al cambiar la contraseña se cierran las demás sesiones: si alguien
        // tenía un token robado, deja de servirle.
        $currentJti = $request->getAttribute('jti');
        $this->sessions->revokeAllForUser((int) $user['id']);

        Logger::audit()->info('Contraseña cambiada', array_merge(
            Logger::requestContext(),
            ['email' => $user['email']]
        ));

        // Se emite una sesión nueva para no expulsar a quien acaba de cambiarla.
        return $this->issueSession($response, $user, $request, 'Contraseña actualizada', [
            'message'          => 'Contraseña actualizada. Se cerraron las demás sesiones.',
            'previousRevoked'  => is_string($currentJti),
        ]);
    }

    /** Reglas mínimas de robustez de contraseña. */
    public static function validatePasswordStrength(string $password): ?string
    {
        if (strlen($password) < 10) {
            return 'La contraseña debe tener al menos 10 caracteres.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'La contraseña debe combinar letras y números.';
        }
        foreach (['password', '12345678', 'qwerty', 'admin123', 'contrasena'] as $common) {
            if (stripos($password, $common) !== false) {
                return 'La contraseña es demasiado predecible; elige otra.';
            }
        }

        return null;
    }

    /** Crea la sesión (JWT + refresh token) y construye la respuesta. */
    private function issueSession(
        Response $response,
        array $user,
        Request $request,
        string $logMessage,
        array $extra = []
    ): Response {
        $jwt = AuthService::issue($user);
        $refresh = AuthService::newRefreshToken();
        $refreshTtl = AuthService::refreshTtl();

        $this->sessions->create(
            $jwt['jti'],
            (int) $user['id'],
            $refresh['hash'],
            $jwt['expiresAt'],
            time() + $refreshTtl,
            RequestSupport::clientIp(),
            $request->getHeaderLine('User-Agent') ?: null
        );

        Logger::app()->info($logMessage, array_merge(
            Logger::requestContext(),
            ['email' => $user['email'], 'userId' => (int) $user['id']]
        ));

        return $this->jsonResponse($response, array_merge([
            'token'            => $jwt['token'],
            'expiresIn'        => $jwt['expiresIn'],
            'refreshToken'     => $refresh['plain'],
            'refreshExpiresIn' => $refreshTtl,
            'user'             => [
                'id'    => (int) $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ], $extra));
    }
}
