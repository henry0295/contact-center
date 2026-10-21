<?php

namespace App\Service;

use App\Repositories\LoginAttemptRepository;

/**
 * Limita los intentos de login para frenar ataques de fuerza bruta.
 *
 * Se aplican dos límites a la vez:
 *   - Por cuenta: protege una cuenta concreta aunque el atacante rote de IP.
 *   - Por IP: frena el barrido de muchas cuentas desde un mismo origen.
 *
 * El bloqueo es por ventana deslizante: no se guarda estado de "bloqueado",
 * simplemente se cuentan los fallos recientes.
 */
class LoginThrottle
{
    /** Ventana de observación. */
    public const WINDOW_SECONDS = 900; // 15 minutos

    /** Fallos permitidos contra una misma cuenta dentro de la ventana. */
    public const MAX_PER_EMAIL = 5;

    /** Fallos permitidos desde una misma IP dentro de la ventana. */
    public const MAX_PER_IP = 20;

    private LoginAttemptRepository $attempts;

    public function __construct(?LoginAttemptRepository $attempts = null)
    {
        $this->attempts = $attempts ?? new LoginAttemptRepository();
    }

    /**
     * @return array{blocked:bool, retryAfter:int, reason:?string}
     */
    public function check(string $ip, ?string $email, bool $includesCurrent = false): array
    {
        // Si el intento en curso ya está registrado (begin()), se permite uno más.
        $extra = $includesCurrent ? 1 : 0;

        if ($email !== null && $email !== '') {
            $byEmail = $this->attempts->failuresByEmail($email, self::WINDOW_SECONDS);
            if ($byEmail >= self::MAX_PER_EMAIL + $extra) {
                return [
                    'blocked'    => true,
                    'retryAfter' => $this->attempts->secondsUntilUnlock($ip, $email, self::WINDOW_SECONDS),
                    'reason'     => 'account',
                ];
            }
        }

        $byIp = $this->attempts->failuresByIp($ip, self::WINDOW_SECONDS);
        if ($byIp >= self::MAX_PER_IP + $extra) {
            return [
                'blocked'    => true,
                'retryAfter' => $this->attempts->secondsUntilUnlock($ip, null, self::WINDOW_SECONDS),
                'reason'     => 'ip',
            ];
        }

        return ['blocked' => false, 'retryAfter' => 0, 'reason' => null];
    }

    /**
     * Registra el intento como fallido ANTES de verificar la contraseña. Si
     * resulta correcto, succeed() lo marca como exitoso. Así las peticiones
     * concurrentes no pueden pasar todas el límite a la vez.
     */
    public function begin(string $ip, string $email): int
    {
        return $this->attempts->record($ip, $email, false);
    }

    public function succeed(int $attemptId, string $email): void
    {
        $this->attempts->markSuccessful($attemptId);
        $this->attempts->clearForEmail($email);
    }

    public function recordFailure(string $ip, ?string $email): void
    {
        $this->attempts->record($ip, $email, false);
    }

    public function recordSuccess(string $ip, string $email): void
    {
        $this->attempts->record($ip, $email, true);
        // Un acceso correcto limpia el contador de esa cuenta.
        $this->attempts->clearForEmail($email);
    }

    /** Intentos fallidos que le quedan a la cuenta antes del bloqueo. */
    public function remainingForEmail(string $email): int
    {
        $used = $this->attempts->failuresByEmail($email, self::WINDOW_SECONDS);

        return max(0, self::MAX_PER_EMAIL - $used);
    }
}
