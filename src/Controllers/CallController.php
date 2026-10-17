<?php

namespace App\Controllers;

use App\Repositories\CampaignRepository;
use App\Service\SendOrchestrator;
use App\Service\VoiceCallService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Compositor de llamadas de voz. Consume la API externa de TTS/Asterisk ya
 * desplegada en 192.168.101.92:8000 (carpeta /opt/apivoz de ese servidor);
 * este proyecto no administra Asterisk, solo origina llamadas por HTTP.
 *
 * Igual que SMS y email, todo envío pasa por SendOrchestrator y queda
 * registrado como campaña (channel = 'call'), con una diferencia importante:
 * el resultado de cada llamada (contestada, ocupado, sin respuesta) no se
 * conoce en la misma petición. Queda "Dialing" hasta que el dispatcher en
 * segundo plano (bin/dispatch-campaigns.php) consulta su estado más tarde.
 */
class CallController
{
    use JsonResponseTrait;

    private SendOrchestrator $orchestrator;
    private CampaignRepository $campaigns;
    private VoiceCallService $voice;

    public function __construct(
        ?SendOrchestrator $orchestrator = null,
        ?CampaignRepository $campaigns = null,
        ?VoiceCallService $voice = null
    ) {
        $this->orchestrator = $orchestrator ?? new SendOrchestrator();
        $this->campaigns = $campaigns ?? new CampaignRepository();
        $this->voice = $voice ?? new VoiceCallService();
    }

    /** Si la API de voz no está configurada, se informa en vez de dejar fallar cada llamada. */
    public function capabilities(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, [
            'configured' => $this->voice->isConfigured(),
            'maxChars'   => 1000,
            'notice'     => $this->voice->isConfigured()
                ? null
                : 'El servicio de llamadas no está disponible en este momento.',
        ]);
    }

    /** Envío desde el compositor: uno, varios o una lista completa. */
    public function send(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        try {
            $result = $this->orchestrator->dispatch([
                'channel'      => 'call',
                'name'         => $data['name'] ?? null,
                'body'         => (string) ($data['message'] ?? $data['body'] ?? ''),
                'recipients'   => $this->recipientsFrom($data),
                'listId'       => !empty($data['listId']) ? (int) $data['listId'] : null,
                'voiceGender'  => $data['voiceGender'] ?? $data['gender'] ?? 'F',
                'tags'         => $data['tags'] ?? null,
                'scheduleType' => $data['scheduleType'] ?? 'now',
                'scheduledAt'  => $data['scheduledAt'] ?? null,
                'expiresAt'    => $data['expiresAt'] ?? null,
                'templateId'   => $data['templateId'] ?? null,
                'origin'       => 'direct',
            ]);
        } catch (\InvalidArgumentException $e) {
            // 402 = saldo insuficiente (InsufficientCreditException).
            return $this->jsonResponse($response, ['error' => $e->getMessage()], $e instanceof \App\Service\InsufficientCreditException ? 402 : 400);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudo registrar la campaña de llamadas',
                'message' => $e->getMessage(),
            ], 500);
        }

        // Las llamadas no resuelven su resultado en la misma petición (quedan
        // "Dialing" hasta que el dispatcher consulte su estado), así que un
        // envío inmediato con 0 fallos "duros" sí es éxito aunque todavía no
        // se sepa quién contestó.
        $allFailed = $result['mode'] === 'sent'
            && (int) ($result['result']['sent'] ?? 0) === 0
            && (int) ($result['result']['failed'] ?? 0) > 0;

        $status = $result['mode'] === 'sent' ? ($allFailed ? 502 : 200) : 202;

        return $this->jsonResponse($response, array_merge($result, ['success' => !$allFailed]), $status);
    }

    /** Llamada de prueba a un solo número. También crea campaña (origin 'test'). */
    public function test(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $to = trim((string) ($data['to'] ?? ''));

        if ($to === '') {
            return $this->jsonResponse($response, ['error' => 'Indica el número para la prueba.'], 400);
        }

        try {
            $result = $this->orchestrator->dispatch([
                'channel'     => 'call',
                'name'        => 'PRUEBA-LLAMADA-' . date('Y-m-d-H:i:s'),
                'body'        => (string) ($data['message'] ?? $data['body'] ?? ''),
                'recipients'  => [$to],
                'voiceGender' => $data['voiceGender'] ?? $data['gender'] ?? 'F',
                'origin'      => 'test',
            ]);
        } catch (\InvalidArgumentException $e) {
            // 402 = saldo insuficiente (InsufficientCreditException).
            return $this->jsonResponse($response, ['error' => $e->getMessage()], $e instanceof \App\Service\InsufficientCreditException ? 402 : 400);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudo originar la llamada de prueba',
                'message' => $e->getMessage(),
            ], 500);
        }

        $originated = ($result['result']['sent'] ?? 0) > 0;

        return $this->jsonResponse($response, array_merge($result, [
            'success' => $originated,
            'message' => $originated
                ? 'Llamada originada hacia ' . $to . '. Asterisk la está marcando; revisa el resultado en Reportes en unos segundos.'
                : 'No se pudo originar la llamada. Revisa el detalle en Reportes.',
        ]), $originated ? 200 : 502);
    }

    /** Campos personalizados disponibles y últimos mensajes enviados. */
    public function fields(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, [
            'fields'   => \App\Support\Sms::availableFields(),
            'previous' => $this->campaigns->recentBodies('call', 10),
        ]);
    }

    /**
     * Normaliza el campo recipients: array nativo, cadena JSON o texto pegado
     * con saltos de línea, comas o punto y coma. Cada elemento puede ser un
     * string suelto o una fila con variables (['name' => ..., 'phone' => ...])
     * proveniente del CSV de personalización.
     *
     * @return array<int,string|array<string,string>>
     */
    private function recipientsFrom(array $data): array
    {
        $raw = $data['recipients'] ?? $data['to'] ?? [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : (preg_split('/[\s,;]+/', $raw) ?: []);
        }

        $out = [];
        foreach ((array) $raw as $v) {
            if (is_array($v)) {
                $out[] = $v;
                continue;
            }
            $v = trim((string) $v);
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }
}
