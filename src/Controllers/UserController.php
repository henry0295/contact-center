<?php

namespace App\Controllers;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController
{
    use JsonResponseTrait;

    /**
     * Roles admitidos. 'admin' gestiona usuarios; 'operator' usa los módulos de
     * envío sin poder administrar cuentas.
     */
    public const ROLES = ['admin', 'operator'];

    private UserRepository $users;
    private SessionRepository $sessions;

    public function __construct(?UserRepository $users = null, ?SessionRepository $sessions = null)
    {
        $this->users = $users ?? new UserRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * El admin asigna una contraseña nueva a otro usuario (p.ej. si la olvidó).
     * Se cierran todas las sesiones de ese usuario.
     */
    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];
        $new = (string) ($data['newPassword'] ?? '');

        $target = $this->users->findById($id);
        if ($target === null) {
            return $this->jsonResponse($response, ['error' => 'Usuario no encontrado'], 404);
        }

        $weak = AuthController::validatePasswordStrength($new);
        if ($weak !== null) {
            return $this->jsonResponse($response, ['error' => $weak], 400);
        }

        $this->users->updatePassword($id, password_hash($new, PASSWORD_BCRYPT));
        $revoked = $this->sessions->revokeAllForUser($id);

        $actor = $request->getAttribute('user');
        Logger::audit()->warning('Contraseña restablecida por un administrador', array_merge(
            Logger::requestContext(),
            ['usuario' => $target['email'], 'por' => $actor['email'] ?? null, 'sesionesCerradas' => $revoked]
        ));

        return $this->jsonResponse($response, [
            'success'          => true,
            'message'          => 'Contraseña restablecida. Se cerraron sus sesiones activas.',
            'sessionsRevoked'  => $revoked,
        ]);
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, ['users' => $this->users->all()]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        // Por defecto el rol con MENOS privilegios: admin hay que pedirlo explícitamente.
        $role = trim((string) ($data['role'] ?? 'operator')) ?: 'operator';

        if ($name === '' || $email === '' || $password === '') {
            return $this->jsonResponse($response, ['error' => 'Nombre, email y contraseña son obligatorios'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonResponse($response, ['error' => 'El email no es válido'], 400);
        }
        $weak = AuthController::validatePasswordStrength($password);
        if ($weak !== null) {
            return $this->jsonResponse($response, ['error' => $weak], 400);
        }
        if (!in_array($role, self::ROLES, true)) {
            return $this->jsonResponse($response, [
                'error' => 'Rol inválido. Valores admitidos: ' . implode(', ', self::ROLES),
            ], 400);
        }
        if ($this->users->findByEmail($email) !== null) {
            return $this->jsonResponse($response, ['error' => 'Ya existe un usuario con ese email'], 409);
        }

        $id = $this->users->create($name, $email, password_hash($password, PASSWORD_BCRYPT), $role);

        $actor = $request->getAttribute('user');
        Logger::audit()->info('Usuario creado', array_merge(Logger::requestContext(), [
            'nuevoUsuario' => $email,
            'rol'          => $role,
            'por'          => $actor['email'] ?? null,
        ]));

        return $this->jsonResponse($response, [
            'success' => true,
            'user'    => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role],
        ], 201);
    }

    /**
     * Edita nombre, email y rol. No se puede cambiar el rol propio (evita que
     * un admin se quite el acceso) ni dejar la instalación sin administradores.
     * Si a otro usuario le cambia el email o el rol, se cierran sus sesiones.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $data = (array) ($request->getParsedBody() ?? []);
        $current = $request->getAttribute('user');

        $target = $this->users->findById($id);
        if ($target === null) {
            return $this->jsonResponse($response, ['error' => 'Usuario no encontrado'], 404);
        }

        $name = trim((string) ($data['name'] ?? $target['name']));
        $email = strtolower(trim((string) ($data['email'] ?? $target['email'])));
        $role = trim((string) ($data['role'] ?? $target['role']));

        if ($name === '' || mb_strlen($name) > 120) {
            return $this->jsonResponse($response, ['error' => 'El nombre es obligatorio (máx. 120 caracteres)'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return $this->jsonResponse($response, ['error' => 'El email no es válido'], 400);
        }
        if (!in_array($role, self::ROLES, true)) {
            return $this->jsonResponse($response, [
                'error' => 'Rol inválido. Valores admitidos: ' . implode(', ', self::ROLES),
            ], 400);
        }

        $isSelf = $current && (int) $current['id'] === $id;
        $roleChanged = $role !== $target['role'];
        $emailChanged = $email !== strtolower((string) $target['email']);

        if ($isSelf && $roleChanged) {
            return $this->jsonResponse($response, ['error' => 'No puedes cambiar tu propio rol'], 400);
        }
        if ($roleChanged && $target['role'] === 'admin' && $this->users->countByRole('admin') <= 1) {
            return $this->jsonResponse($response, ['error' => 'Debe quedar al menos un administrador'], 400);
        }
        if ($emailChanged) {
            $other = $this->users->findByEmail($email);
            if ($other !== null && (int) $other['id'] !== $id) {
                return $this->jsonResponse($response, ['error' => 'Ya existe un usuario con ese email'], 409);
            }
        }

        $this->users->update($id, $name, $email, $role);

        // El token lleva el email: a otro usuario se le cierran las sesiones
        // para que vuelva a entrar con los datos nuevos. Al propio usuario no.
        $revoked = 0;
        if (!$isSelf && ($emailChanged || $roleChanged)) {
            $revoked = $this->sessions->revokeAllForUser($id);
        }

        Logger::audit()->info('Usuario editado', array_merge(Logger::requestContext(), [
            'usuario'          => $target['email'],
            'emailNuevo'       => $emailChanged ? $email : null,
            'rolNuevo'         => $roleChanged ? $role : null,
            'por'              => $current['email'] ?? null,
            'sesionesCerradas' => $revoked,
        ]));

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => $revoked > 0 ? 'Usuario actualizado. Se cerraron sus sesiones activas.' : 'Usuario actualizado.',
            'user'    => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role],
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $current = $request->getAttribute('user');

        if ($current && (int) $current['id'] === $id) {
            return $this->jsonResponse($response, ['error' => 'No puedes eliminar tu propio usuario'], 400);
        }

        $target = $this->users->findById($id);
        $deleted = $this->users->delete($id);
        if (!$deleted) {
            return $this->jsonResponse($response, ['error' => 'Usuario no encontrado'], 404);
        }

        Logger::audit()->warning('Usuario eliminado', array_merge(Logger::requestContext(), [
            'usuarioEliminado' => $target['email'] ?? $id,
            'por'              => $current['email'] ?? null,
        ]));

        return $this->jsonResponse($response, ['success' => true]);
    }
}
