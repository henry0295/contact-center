<?php

namespace App\Controllers;

use App\Repositories\MessageRepository;
use App\Repositories\SuppressionRepository;
use App\Support\Logger;
use Aws\Sns\Message as SnsMessage;
use Aws\Sns\MessageValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Recibe las notificaciones de Amazon SNS sobre rebotes (bounces) y quejas
 * (complaints) de SES, y alimenta la lista de supresión.
 *
 * AWS vigila la tasa de rebotes y quejas de cada cuenta: si se sigue enviando
 * a direcciones que rebotan, acaba suspendiendo el envío. Por eso este webhook
 * es prácticamente obligatorio en cuanto hay volumen.
 *
 * La ruta es PÚBLICA (la llama AWS, no un usuario), así que la autenticidad se
 * comprueba validando la FIRMA criptográfica del mensaje de SNS. Sin eso,
 * cualquiera podría envenenar la lista de supresión y bloquear envíos.
 */
class WebhookController
{
    use JsonResponseTrait;

    private SuppressionRepository $suppressions;
    private MessageRepository $messages;
    private ?MessageValidator $validator;

    public function __construct(
        ?SuppressionRepository $suppressions = null,
        ?MessageRepository $messages = null,
        ?MessageValidator $validator = null
    ) {
        $this->suppressions = $suppressions ?? new SuppressionRepository();
        $this->messages = $messages ?? new MessageRepository();
        $this->validator = $validator;
    }

    public function ses(Request $request, Response $response): Response
    {
        $raw = (string) $request->getBody();

        if ($raw === '') {
            return $this->jsonResponse($response, ['error' => 'Cuerpo vacío'], 400);
        }

        try {
            $message = new SnsMessage(json_decode($raw, true) ?: []);
        } catch (\Throwable $e) {
            Logger::app()->warning('Webhook SNS con cuerpo inválido', ['error' => $e->getMessage()]);

            return $this->jsonResponse($response, ['error' => 'Mensaje SNS inválido'], 400);
        }

        // Validación de firma: imprescindible para no aceptar notificaciones
        // falsificadas. Se puede desactivar solo para pruebas locales.
        if ($this->signatureRequired()) {
            try {
                ($this->validator ?? new MessageValidator())->validate($message);
            } catch (\Throwable $e) {
                Logger::app()->warning('Webhook SNS con firma inválida', array_merge(
                    Logger::requestContext(),
                    ['error' => $e->getMessage()]
                ));

                return $this->jsonResponse($response, ['error' => 'Firma no válida'], 403);
            }
        }

        $type = $message['Type'] ?? '';

        // Alta de la suscripción: AWS espera que se visite la URL de confirmación.
        if ($type === 'SubscriptionConfirmation') {
            return $this->confirmSubscription($response, $message);
        }

        if ($type !== 'Notification') {
            return $this->jsonResponse($response, ['success' => true, 'ignored' => $type]);
        }

        $payload = json_decode($message['Message'] ?? '{}', true);
        if (!is_array($payload)) {
            return $this->jsonResponse($response, ['error' => 'Notificación ilegible'], 400);
        }

        return $this->handleNotification($response, $payload);
    }

    private function confirmSubscription(Response $response, SnsMessage $message): Response
    {
        $url = $message['SubscribeURL'] ?? '';

        // Solo se visitan URL de AWS: evita convertir el webhook en un proxy.
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (!preg_match('/(^|\.)amazonaws\.com$/', $host)) {
            Logger::app()->warning('SubscribeURL sospechosa en webhook SNS', ['host' => $host]);

            return $this->jsonResponse($response, ['error' => 'SubscribeURL no válida'], 400);
        }

        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $ok = @file_get_contents($url, false, $context);

        Logger::app()->info('Suscripción SNS confirmada', ['host' => $host, 'ok' => $ok !== false]);

        return $this->jsonResponse($response, ['success' => $ok !== false, 'confirmed' => true]);
    }

    /**
     * Procesa los tipos de notificación de SES. Solo los rebotes PERMANENTES y
     * las quejas van a la lista de supresión: un rebote transitorio (buzón
     * lleno, servidor caído) puede resolverse por sí solo.
     */
    private function handleNotification(Response $response, array $payload): Response
    {
        $type = $payload['notificationType'] ?? $payload['eventType'] ?? '';
        $added = [];

        if ($type === 'Bounce') {
            $bounce = $payload['bounce'] ?? [];
            $permanent = ($bounce['bounceType'] ?? '') === 'Permanent';
            $detail = trim(($bounce['bounceType'] ?? '') . '/' . ($bounce['bounceSubType'] ?? ''), '/');

            foreach ($bounce['bouncedRecipients'] ?? [] as $recipient) {
                $email = $recipient['emailAddress'] ?? null;
                if ($email === null) {
                    continue;
                }

                $this->messages->log('email', $email, null, 'Failed', 'Rebote: ' . $detail);

                if ($permanent) {
                    $this->suppressions->add($email, 'bounce', $detail . ' ' . ($recipient['diagnosticCode'] ?? ''));
                    $added[] = $email;
                }
            }

            Logger::mail()->warning('Rebote recibido', [
                'tipo'        => $detail,
                'permanente'  => $permanent,
                'suprimidos'  => count($added),
            ]);
        } elseif ($type === 'Complaint') {
            $complaint = $payload['complaint'] ?? [];

            foreach ($complaint['complainedRecipients'] ?? [] as $recipient) {
                $email = $recipient['emailAddress'] ?? null;
                if ($email === null) {
                    continue;
                }
                // Una queja siempre se suprime: el destinatario marcó el correo
                // como spam y seguir enviándole es lo peor para la reputación.
                $this->suppressions->add($email, 'complaint', $complaint['complaintFeedbackType'] ?? null);
                $this->messages->log('email', $email, null, 'Failed', 'Queja de spam');
                $added[] = $email;
            }

            Logger::mail()->warning('Queja recibida', ['suprimidos' => count($added)]);
        } elseif ($type === 'Delivery') {
            Logger::mail()->info('Entrega confirmada por SES', [
                'destinatarios' => $payload['delivery']['recipients'] ?? [],
            ]);
        } else {
            Logger::mail()->info('Notificación SES no manejada', ['tipo' => $type]);
        }

        return $this->jsonResponse($response, [
            'success'    => true,
            'type'       => $type,
            'suppressed' => $added,
        ]);
    }

    /** En local se puede omitir la firma con SNS_VERIFY_SIGNATURE=false. */
    private function signatureRequired(): bool
    {
        $value = $_ENV['SNS_VERIFY_SIGNATURE'] ?? 'true';

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
