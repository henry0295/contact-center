<?php

namespace App\Service;

use App\Support\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Cliente de la API de llamadas de voz (TTS + Asterisk).
 *
 * Esa API es un servicio EXTERNO ya desplegado en otro servidor
 * (192.168.101.92:8000, carpeta /opt/apivoz): Node.js + Piper TTS para
 * generar la locución y Asterisk (vía AMI, dentro de esa API) para marcar
 * de verdad. Este proyecto NO administra ese servidor, solo lo consume por
 * HTTP con una API Key propia (VOICE_API_KEY en el .env).
 *
 * Dos llamadas HTTP por envío, igual que SES/SNS:
 *   1. POST /tts-call -> genera el audio y ORIGINA la llamada. Responde de
 *      inmediato con un call_id; el resultado real (contestada, ocupado...)
 *      llega más tarde porque Asterisk tarda en marcar y la persona en
 *      contestar.
 *   2. GET /call-status/{id} -> se consulta después (desde el dispatcher)
 *      para saber en qué quedó.
 */
class VoiceCallService
{
    /** Estados que la API de voz considera definitivos (ya no cambian). */
    public const FINAL_STATUSES = ['answered', 'busy', 'rechazado'];

    private Client $http;
    private string $apiKey;

    public function __construct(?Client $http = null)
    {
        $baseUrl = rtrim((string) ($_ENV['VOICE_API_URL'] ?? ''), '/');
        $this->apiKey = (string) ($_ENV['VOICE_API_KEY'] ?? '');

        $this->http = $http ?? new Client([
            'base_uri' => $baseUrl !== '' ? $baseUrl . '/' : '',
            'timeout'  => 15,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && (string) ($_ENV['VOICE_API_URL'] ?? '') !== '';
    }

    /**
     * Origina la llamada. `$message` ya debe venir con las variables
     * resueltas (igual que el SMS/email: el llamador decide el texto final).
     *
     * @return array{callId:string, audioFile:string}
     * @throws \RuntimeException si la API de voz rechaza la petición o no responde
     */
    public function call(string $phone, string $message, string $gender = 'F'): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'La API de llamadas de voz no está configurada. Ejecuta en el servidor: '
                . 'sudo VOICE_ADMIN_KEY=\'<clave>\' /var/www/contact-center/deploy.sh update'
            );
        }

        try {
            $response = $this->http->post('tts-call', [
                'headers' => ['X-API-Key' => $this->apiKey],
                'json'    => [
                    'to'      => $phone,
                    'message' => $message,
                    'gender'  => $gender === 'M' ? 'M' : 'F',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || empty($data['call_id'])) {
                throw new \RuntimeException('Respuesta inesperada de la API de voz.');
            }

            Logger::mail()->info('Llamada de voz originada', [
                'to'     => $phone,
                'callId' => $data['call_id'],
            ]);

            return [
                'callId'    => (string) $data['call_id'],
                'audioFile' => (string) ($data['audio'] ?? ''),
            ];
        } catch (GuzzleException $e) {
            $detail = $this->extractErrorMessage($e);
            Logger::mail()->error('Fallo al originar llamada de voz', [
                'to'    => $phone,
                'error' => $detail,
            ]);

            throw new \RuntimeException($detail, 0, $e);
        }
    }

    /**
     * Consulta el estado de una llamada ya originada.
     *
     * @return string uno de: ringing, answered, busy, rechazado, desconocido
     */
    public function status(string $callId): string
    {
        try {
            $response = $this->http->get('call-status/' . rawurlencode($callId), [
                'headers' => ['X-API-Key' => $this->apiKey],
            ]);
            $data = json_decode((string) $response->getBody(), true);

            return is_array($data) ? (string) ($data['status'] ?? 'desconocido') : 'desconocido';
        } catch (GuzzleException $e) {
            Logger::mail()->warning('No se pudo consultar el estado de la llamada', [
                'callId' => $callId,
                'error'  => $e->getMessage(),
            ]);

            return 'desconocido';
        }
    }

    /** Traduce errores HTTP comunes (401/403/429) a algo accionable. */
    private function extractErrorMessage(GuzzleException $e): string
    {
        $raw = $e->getMessage();

        if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
            $body = (string) $e->getResponse()->getBody();
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['error'])) {
                return (string) $decoded['error'];
            }
        }

        if (stripos($raw, 'Could not resolve host') !== false || stripos($raw, 'Connection refused') !== false) {
            return 'No se pudo conectar con la API de llamadas de voz (' . ($_ENV['VOICE_API_URL'] ?? '')
                . '). Comprueba que el servidor Asterisk esté accesible.';
        }

        return $raw;
    }
}
