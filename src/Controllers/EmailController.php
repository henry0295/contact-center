<?php

namespace App\Controllers;

use App\Repositories\CampaignRepository;
use App\Service\LinkChecker;
use App\Service\LinkCheckException;
use App\Service\SendOrchestrator;
use App\Support\Attachments;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Compositor de email. Igual que en SMS, todo envío pasa por SendOrchestrator
 * y queda registrado como campaña: así los informes cubren toda la actividad y
 * existe un único procesador de envíos.
 *
 * Acepta JSON (sin adjuntos) o multipart/form-data con el campo "attachments[]".
 */
class EmailController
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

    public function send(Request $request, Response $response): Response
    {
        return $this->dispatch($request, $response, 'direct', null);
    }

    /** Compatibilidad con el cliente anterior: mismo camino que send(). */
    public function sendBulk(Request $request, Response $response): Response
    {
        return $this->dispatch($request, $response, 'direct', null);
    }

    /** Envío de prueba a una sola dirección (queda como campaña 'test'). */
    public function test(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $to = trim((string) ($data['to'] ?? ''));

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->jsonResponse($response, ['error' => 'Indica un correo válido para la prueba.'], 400);
        }

        return $this->dispatch($request, $response, 'test', [$to]);
    }

    /**
     * @param array<int,string>|null $forceRecipients destinatarios fijos (prueba)
     */
    private function dispatch(
        Request $request,
        Response $response,
        string $origin,
        ?array $forceRecipients
    ): Response {
        $data = $request->getParsedBody() ?? [];

        try {
            $attachments = Attachments::fromUploads($request->getUploadedFiles()['attachments'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($origin === 'test') {
            $name = 'PRUEBA-EMAIL-' . date('Y-m-d-H:i:s');
        }

        try {
            $result = $this->orchestrator->dispatch([
                'channel'      => 'email',
                'name'         => $name !== '' ? $name : null,
                'subject'      => $data['subject'] ?? null,
                'body'         => (string) ($data['body'] ?? $data['message'] ?? ''),
                'recipients'   => $forceRecipients ?? $this->recipientsFrom($data),
                'listId'       => ($forceRecipients === null && !empty($data['listId'])) ? (int) $data['listId'] : null,
                'tags'         => $data['tags'] ?? null,
                'scheduleType' => $origin === 'test' ? 'now' : ($data['scheduleType'] ?? 'now'),
                'scheduledAt'  => $data['scheduledAt'] ?? null,
                'expiresAt'    => $data['expiresAt'] ?? null,
                'templateId'   => $data['templateId'] ?? null,
                'origin'       => $origin,
                'attachments'  => $attachments,
                // Ausente = email de respuesta por defecto; 'none' = ninguno.
                'replyTo'      => array_key_exists('replyTo', $data) ? $data['replyTo'] : null,
                // El usuario confirmó los avisos de links (multipart envía "true"/"1").
                'acceptLinkWarnings' => filter_var($data['acceptLinkWarnings'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        } catch (LinkCheckException $e) {
            Attachments::cleanup($attachments);

            return $this->jsonResponse($response, [
                'error'             => $e->getMessage(),
                'code'              => $e->needsConfirmation() ? 'link_warnings' : 'link_blocked',
                'needsConfirmation' => $e->needsConfirmation(),
                'linkCheck'         => $e->result(),
            ], 422);
        } catch (\App\Service\InsufficientCreditException $e) {
            Attachments::cleanup($attachments);

            return $this->jsonResponse($response, ['error' => $e->getMessage(), 'code' => 'insufficient_credit'], 402);
        } catch (\InvalidArgumentException $e) {
            Attachments::cleanup($attachments);

            return $this->jsonResponse($response, ['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            Attachments::cleanup($attachments);

            return $this->jsonResponse($response, [
                'error'   => 'No se pudo registrar el envío',
                'message' => $this->friendlyError($e),
            ], 500);
        }

        // Los adjuntos que se movieron a la carpeta de la campaña ya no están en
        // temporales; cleanup() solo borra lo que quedara sin mover.
        Attachments::cleanup($attachments);

        // Traducción amable del primer error de SES, si lo hubo.
        if (!empty($result['result']['failed'])) {
            $result['hint'] = $this->firstRecipientHint((int) $result['campaign']['id']);
        }

        // Un envío inmediato en el que no se entregó NADA no es un éxito: si se
        // devolviera 200 con success=true, la interfaz mostraría "enviado"
        // mientras el destinatario no ha recibido nada.
        $allFailed = $result['mode'] === 'sent' && (int) ($result['result']['sent'] ?? 0) === 0;

        if ($result['mode'] === 'sent') {
            $status = $allFailed ? 502 : 200;
        } else {
            $status = 202;
        }

        return $this->jsonResponse($response, array_merge($result, ['success' => !$allFailed]), $status);
    }

    /**
     * Revisión de links en vivo para el compositor y el editor de plantillas.
     * body: {body}. No envía nada.
     */
    public function checkLinks(Request $request, Response $response): Response
    {
        $body = (string) (($request->getParsedBody() ?? [])['body'] ?? '');
        if (strlen($body) > 2 * 1024 * 1024) {
            return $this->jsonResponse($response, ['error' => 'El mensaje es demasiado grande.'], 413);
        }
        $result = LinkChecker::fromEnvironment()->check($body);

        return $this->jsonResponse($response, $result + [
            'hasVariableLinks' => LinkChecker::hasVariableLinks($body),
        ]);
    }

    /** Campos personalizados y mensajes anteriores para el compositor. */
    public function fields(Request $request, Response $response): Response
    {
        return $this->jsonResponse($response, [
            'fields'   => \App\Support\Sms::availableFields(),
            'previous' => $this->campaigns->recentBodies('email', 10),
        ]);
    }

    /**
     * Reúne destinatarios: array nativo, cadena JSON (multipart) o texto pegado.
     * Cada elemento puede ser un string suelto o una fila con variables
     * (['name' => ..., 'email' => ...]) proveniente del CSV de personalización;
     * SendOrchestrator::resolveRecipients() distingue ambos casos.
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
            $v = trim((string) $v, " \t\n\r\0\x0B\"'[]");
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** Toma el error del primer destinatario fallido y lo hace accionable. */
    private function firstRecipientHint(int $campaignId): ?string
    {
        try {
            $rows = (new \App\Repositories\CampaignRecipientRepository())->forCampaign($campaignId);
            foreach ($rows as $row) {
                if (($row['status'] ?? '') === 'Failed' && !empty($row['error'])) {
                    return $this->friendlyError(new \RuntimeException((string) $row['error']));
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Traduce los errores típicos de SES a algo accionable. El caso más común en
     * cuentas nuevas es el sandbox: solo se puede enviar a identidades verificadas.
     */
    private function friendlyError(\Throwable $e): string
    {
        $raw = $e->getMessage();

        if (stripos($raw, 'not verified') !== false || stripos($raw, 'MessageRejected') !== false) {
            return 'El destinatario no está verificado en Amazon SES. Tu cuenta está en modo '
                . 'sandbox, así que solo puedes enviar a direcciones o dominios verificados. '
                . 'Verifica el correo del destinatario en el módulo de Verificación, o solicita '
                . 'el acceso de producción en SES para enviar a cualquier dirección. '
                . '(Detalle de AWS: ' . $raw . ')';
        }

        if (stripos($raw, 'Message too long') !== false || stripos($raw, 'exceeds the limit') !== false) {
            return 'El mensaje con sus adjuntos supera el tamaño permitido por Amazon SES. '
                . 'Reduce el peso de los archivos. (Detalle de AWS: ' . $raw . ')';
        }

        return $raw;
    }
}
