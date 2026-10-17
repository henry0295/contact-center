<?php

namespace App\Controllers;

use App\Repositories\CampaignRepository;
use App\Service\SendOrchestrator;
use App\Support\Sms;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Compositor de SMS. Todos los envíos (individual, masivo, de prueba) pasan por
 * SendOrchestrator y quedan registrados como campaña, así que el historial y
 * los informes cubren el 100% de la actividad.
 */
class SmsController
{
    use JsonResponseTrait;

    private SendOrchestrator $orchestrator;
    private CampaignRepository $campaigns;

    public function __construct(
        ?SendOrchestrator $orchestrator = null,
        ?CampaignRepository $campaigns = null
    ) {
        $this->orchestrator = $orchestrator ?? new SendOrchestrator();
        $this->campaigns = $campaigns ?? new CampaignRepository();
    }

    /** Envío desde el compositor: uno, varios o una lista completa. */
    public function send(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        try {
            $result = $this->orchestrator->dispatch([
                'channel'      => 'sms',
                'name'         => $data['name'] ?? null,
                'body'         => (string) ($data['message'] ?? $data['body'] ?? ''),
                'recipients'   => $this->recipientsFrom($data),
                'listId'       => !empty($data['listId']) ? (int) $data['listId'] : null,
                'senderId'     => $data['senderId'] ?? $data['sender'] ?? null,
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
                'error'   => 'No se pudo registrar el envío',
                'message' => $e->getMessage(),
            ], 500);
        }

        // Si el envío fue inmediato y no se entregó ni uno, no es un éxito:
        // devolver 200/success haría que la interfaz dijera "enviado" sin serlo.
        $allFailed = $result['mode'] === 'sent' && (int) ($result['result']['sent'] ?? 0) === 0;

        // 202 cuando queda pendiente de procesarse (programado o en cola).
        $status = $result['mode'] === 'sent' ? ($allFailed ? 502 : 200) : 202;

        return $this->jsonResponse($response, array_merge($result, ['success' => !$allFailed]), $status);
    }

    /**
     * Se mantiene por compatibilidad con el cliente anterior: el compositor ya
     * no distingue entre individual y masivo.
     */
    public function sendBulk(Request $request, Response $response): Response
    {
        return $this->send($request, $response);
    }

    /**
     * Envío de prueba a un solo número. También crea campaña (origin 'test')
     * para que quede rastro, pero los informes la pueden filtrar aparte.
     */
    public function test(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $to = trim((string) ($data['to'] ?? ''));

        if ($to === '') {
            return $this->jsonResponse($response, ['error' => 'Indica el número para la prueba.'], 400);
        }

        try {
            $result = $this->orchestrator->dispatch([
                'channel'    => 'sms',
                'name'       => 'PRUEBA-SMS-' . date('Y-m-d-H:i:s'),
                'body'       => (string) ($data['message'] ?? $data['body'] ?? ''),
                'recipients' => [$to],
                'senderId'   => $data['senderId'] ?? $data['sender'] ?? null,
                'origin'     => 'test',
            ]);
        } catch (\InvalidArgumentException $e) {
            // 402 = saldo insuficiente (InsufficientCreditException).
            return $this->jsonResponse($response, ['error' => $e->getMessage()], $e instanceof \App\Service\InsufficientCreditException ? 402 : 400);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, [
                'error'   => 'No se pudo enviar la prueba',
                'message' => $e->getMessage(),
            ], 500);
        }

        $sent = ($result['result']['sent'] ?? 0) > 0;

        return $this->jsonResponse($response, array_merge($result, [
            'success' => $sent,
            'message' => $sent
                ? 'SMS de prueba enviado a ' . $to . '.'
                : 'La prueba no se pudo entregar. Revisa el detalle en Reportes.',
        ]), $sent ? 200 : 502);
    }

    /**
     * Segmentos, codificación y coste estimado del texto actual. La interfaz lo
     * llama mientras se escribe, así que no toca AWS ni envía nada.
     */
    public function estimate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $text = (string) ($data['message'] ?? $data['body'] ?? '');
        $recipients = max(0, (int) ($data['recipients'] ?? 0));

        $analysis = Sms::analyze($text);

        return $this->jsonResponse($response, [
            'analysis' => $analysis,
            'cost'     => Sms::estimateCost($analysis['segments'], $recipients),
        ]);
    }

    /** Campos personalizados disponibles y últimos mensajes enviados. */
    public function fields(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, [
            'fields'   => Sms::availableFields(),
            'previous' => $this->campaigns->recentBodies('sms', 10),
        ]);
    }

    /**
     * Normaliza el campo recipients: array nativo, cadena JSON o texto pegado
     * con saltos de línea, comas o punto y coma. Cada elemento puede ser un
     * string suelto o una fila con variables (['name' => ..., 'phone' => ...])
     * proveniente del CSV de personalización; SendOrchestrator::resolveRecipients()
     * distingue ambos casos.
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
