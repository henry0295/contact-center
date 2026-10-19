<?php

namespace App\Service;

use App\Support\Aws;
use App\Support\Logger;
use Aws\Sns\SnsClient;
use GuzzleHttp\Client;

/**
 * Envío de SMS. Dos proveedores, según SMS_PROVIDER del .env:
 *
 *   itcloud (por defecto): IT Cloud Services / contacto-virtual.com.
 *       POST JSON {token, email, type_send, data:[{cellphone, message}]}
 *       Responde por número {celular, id_sms}: id_sms > 0 = aceptado,
 *       negativo = código de error (ver ITCLOUD_ERRORS). Cada instalación
 *       usa SU email y token (los pide deploy.sh / bin/sms-setup.php), y el
 *       proveedor debe tener registrada en su firewall la IP pública del
 *       servidor.
 *
 *   sns: Amazon SNS (comportamiento anterior).
 */
class SmsService
{
    public const ITCLOUD_SEND_URL = 'https://contacto-virtual.com/a/api/send/sms/json';
    public const ITCLOUD_BALANCE_URL = 'https://contacto-virtual.com/a/api/sendsms/saldo';

    /** Máximo de caracteres por mensaje que acepta IT Cloud. */
    public const ITCLOUD_MAX_LENGTH = 757;

    /** Códigos negativos de id_sms (documentación del proveedor, v2). */
    public const ITCLOUD_ERRORS = [
        -1  => 'Autenticación fallida con el proveedor de SMS (email o token inválidos).',
        -2  => 'Fuera del horario autorizado por la CRC para enviar SMS.',
        -3  => 'La cuenta del proveedor de SMS no tiene saldo.',
        -4  => 'Número de celular inválido (se esperan 12 dígitos con el prefijo del país, p.ej. 573001234567).',
        -5  => 'Mensaje vacío o de más de 757 caracteres.',
        -6  => 'El proveedor de SMS está en mantenimiento.',
        -8  => 'El número está en la lista de bloqueo (respondió SALIR o fue bloqueado).',
        -9  => 'Mensaje rechazado por contenido (palabras reservadas).',
        -10 => 'El mensaje contiene un enlace no autorizado por el proveedor.',
        -11 => 'Tipo de envío inválido (1via, 2via o flash).',
    ];

    private ?SnsClient $sns;
    private ?Client $http;

    public function __construct(?SnsClient $sns = null, ?Client $http = null)
    {
        $this->sns = $sns;
        $this->http = $http;
    }

    public static function provider(): string
    {
        $p = strtolower(trim((string) ($_ENV['SMS_PROVIDER'] ?? 'itcloud')));

        return $p === 'sns' ? 'sns' : 'itcloud';
    }

    public function isConfigured(): bool
    {
        if (self::provider() === 'sns') {
            return true;
        }

        return trim((string) ($_ENV['ITCLOUD_SMS_EMAIL'] ?? '')) !== ''
            && trim((string) ($_ENV['ITCLOUD_SMS_TOKEN'] ?? '')) !== '';
    }

    /**
     * Número en el formato de IT Cloud: prefijo de país + número, solo
     * dígitos. Un celular colombiano de 10 dígitos (3xx...) recibe el 57.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && $digits[0] === '3') {
            $digits = '57' . $digits;
        }

        return $digits;
    }

    /**
     * @param string|null $senderId Remitente alfanumérico. Solo lo usa SNS;
     *   IT Cloud envía con el remitente de la cuenta.
     */
    public function send(string $phone, string $message, ?string $senderId = null, ?string $type = null): void
    {
        if (self::provider() === 'sns') {
            $this->sendSns($phone, $message, $senderId);

            return;
        }

        $this->sendItCloud($phone, $message, $type);
    }

    /** @param string|null $type '1via' | '2via' | 'flash'; null = ITCLOUD_SMS_TYPE del .env */
    private function sendItCloud(string $phone, string $message, ?string $type = null): void
    {
        $email = trim((string) ($_ENV['ITCLOUD_SMS_EMAIL'] ?? ''));
        $token = trim((string) ($_ENV['ITCLOUD_SMS_TOKEN'] ?? ''));
        if ($email === '' || $token === '') {
            throw new \RuntimeException(
                'El proveedor de SMS no está configurado (ITCLOUD_SMS_EMAIL / ITCLOUD_SMS_TOKEN). '
                . 'Ejecuta en el servidor: sudo /var/www/contact-center/deploy.sh update'
            );
        }

        $cellphone = self::normalizePhone($phone);
        if (mb_strlen($message) > self::ITCLOUD_MAX_LENGTH) {
            throw new \RuntimeException(self::ITCLOUD_ERRORS[-5]);
        }

        $type = $type ?: (string) ($_ENV['ITCLOUD_SMS_TYPE'] ?? '1via');
        if (!in_array($type, ['1via', '2via', 'flash'], true)) {
            $type = '1via';
        }

        try {
            $res = $this->http()->post((string) ($_ENV['ITCLOUD_SMS_URL'] ?? '') ?: self::ITCLOUD_SEND_URL, [
                'json' => [
                    'token'     => $token,
                    'email'     => $email,
                    'type_send' => $type,
                    'data'      => [['cellphone' => $cellphone, 'message' => $message]],
                ],
                'headers'     => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            Logger::mail()->error('Fallo de conexión con el proveedor de SMS', ['to' => $cellphone, 'error' => $e->getMessage()]);
            throw new \RuntimeException('No se pudo conectar con el proveedor de SMS: ' . $e->getMessage(), 0, $e);
        }

        $raw = (string) $res->getBody();
        $data = json_decode($raw, true);
        $id = is_array($data) ? ($data['data'][0]['id_sms'] ?? null) : null;

        // Errores de la petición completa (p.ej. -1 credenciales) llegan como
        // {"status":"Error","type_error":-1,"message":"..."} sin "data".
        if ($id === null && is_array($data) && isset($data['type_error']) && is_numeric($data['type_error'])) {
            $id = (int) $data['type_error'];
            if (!isset(self::ITCLOUD_ERRORS[$id]) && !empty($data['message'])) {
                Logger::mail()->warning('SMS rechazado por el proveedor', ['to' => $cellphone, 'code' => $id]);
                throw new \RuntimeException('Proveedor de SMS: ' . mb_substr((string) $data['message'], 0, 200) . ' (código ' . $id . ').');
            }
        }

        if ($res->getStatusCode() >= 400 || !is_numeric($id)) {
            Logger::mail()->error('Respuesta inesperada del proveedor de SMS', [
                'to' => $cellphone, 'http' => $res->getStatusCode(), 'body' => mb_substr($raw, 0, 300),
            ]);
            throw new \RuntimeException(
                'El proveedor de SMS respondió de forma inesperada (HTTP ' . $res->getStatusCode() . '). '
                . 'Si es la primera vez, confirma con el proveedor que la IP pública de este servidor está autorizada.'
            );
        }

        $id = (int) $id;
        if ($id <= 0) {
            $msg = self::ITCLOUD_ERRORS[$id] ?? ('El proveedor de SMS rechazó el envío (código ' . $id . ').');
            Logger::mail()->warning('SMS rechazado por el proveedor', ['to' => $cellphone, 'code' => $id]);
            throw new \RuntimeException($msg);
        }

        Logger::mail()->info('SMS enviado', ['to' => $cellphone, 'provider' => 'itcloud', 'id_sms' => $id]);
    }

    private function sendSns(string $phone, string $message, ?string $senderId): void
    {
        $attributes = [
            // Transactional da prioridad de entrega y mejor fiabilidad.
            'AWS.SNS.SMS.SMSType' => ['DataType' => 'String', 'StringValue' => 'Transactional'],
        ];

        $senderId = $senderId === null ? '' : trim($senderId);
        if ($senderId !== '') {
            $attributes['AWS.SNS.SMS.SenderID'] = ['DataType' => 'String', 'StringValue' => $senderId];
        }

        try {
            $this->sns ??= new SnsClient(Aws::clientConfig());
            $result = $this->sns->publish([
                'PhoneNumber'       => $phone,
                'Message'           => $message,
                'MessageAttributes' => $attributes,
            ]);

            Logger::mail()->info('SMS enviado', [
                'to'        => $phone,
                'provider'  => 'sns',
                'sender'    => $senderId !== '' ? $senderId : null,
                'messageId' => $result['MessageId'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Logger::mail()->error('Fallo al enviar SMS', ['to' => $phone, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    private function http(): Client
    {
        return $this->http ??= new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }
}
