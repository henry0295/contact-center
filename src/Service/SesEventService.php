<?php

namespace App\Service;

use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\MessageRepository;
use App\Repositories\SuppressionRepository;
use App\Support\Aws;
use App\Support\Logger;
use Aws\Sqs\SqsClient;

/**
 * Estados de entrega de los emails (SES -> EventBridge -> cola SQS de esta
 * instalación). El dispatcher llama a poll() cada minuto: la app solo hace
 * conexiones salientes a AWS, sin webhook público.
 *
 * Eventos que se procesan (los que crea bin/ses-events.php setup):
 *   Delivery          -> delivery_status = delivered
 *   Bounce Permanent  -> destinatario Failed + lista de supresión (no se le
 *                        vuelve a enviar). NO se reembolsa: SES sí lo envió.
 *   Bounce Transient  -> delivery_status = soft_bounce (no se bloquea)
 *   Reject            -> destinatario Failed (SES no lo envió, p.ej. virus)
 *   Complaint         -> lista de supresión (por si se activa más adelante)
 *
 * Es idempotente: SQS puede entregar un mensaje más de una vez.
 */
class SesEventService
{
    /** Orden de los estados: uno "menor" no pisa a uno "mayor". */
    private const RANK = ['accepted' => 0, 'soft_bounce' => 1, 'delivered' => 2, 'rejected' => 3, 'bounced' => 3, 'complaint' => 4];

    private ?SqsClient $sqs;
    private CampaignRecipientRepository $recipients;
    private SuppressionRepository $suppressions;
    private MessageRepository $messages;
    private CampaignRepository $campaigns;

    public function __construct(
        ?SqsClient $sqs = null,
        ?CampaignRecipientRepository $recipients = null,
        ?SuppressionRepository $suppressions = null,
        ?MessageRepository $messages = null,
        ?CampaignRepository $campaigns = null
    ) {
        $this->sqs = $sqs;
        $this->recipients = $recipients ?? new CampaignRecipientRepository();
        $this->suppressions = $suppressions ?? new SuppressionRepository();
        $this->messages = $messages ?? new MessageRepository();
        $this->campaigns = $campaigns ?? new CampaignRepository();
    }

    public static function queueUrl(): ?string
    {
        $v = trim((string) ($_ENV['SES_EVENTS_QUEUE_URL'] ?? ''), " \"'");

        return preg_match('#^https://sqs\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?/\d{12}/[A-Za-z0-9_\-]{1,80}$#', $v) ? $v : null;
    }

    public static function isConfigured(): bool
    {
        return self::queueUrl() !== null && EmailService::configurationSet() !== null;
    }

    /**
     * Lee y procesa los eventos pendientes de la cola.
     *
     * @return array{received:int, processed:int, ignored:int, errors:int}
     */
    public function poll(int $maxBatches = 30): array
    {
        $stats = ['received' => 0, 'processed' => 0, 'ignored' => 0, 'errors' => 0];
        $url = self::queueUrl();
        if ($url === null) {
            return $stats;
        }
        $this->sqs ??= new SqsClient(Aws::clientConfig());

        for ($i = 0; $i < $maxBatches; $i++) {
            $res = $this->sqs->receiveMessage([
                'QueueUrl'            => $url,
                'MaxNumberOfMessages' => 10,
                'WaitTimeSeconds'     => $i === 0 ? 1 : 0,
                'VisibilityTimeout'   => 60,
            ]);
            $batch = $res['Messages'] ?? [];
            if (empty($batch)) {
                break;
            }
            $done = [];
            foreach ($batch as $m) {
                $stats['received']++;
                try {
                    $ok = $this->handle((string) ($m['Body'] ?? ''));
                    $stats[$ok ? 'processed' : 'ignored']++;
                    $done[] = ['Id' => (string) count($done), 'ReceiptHandle' => $m['ReceiptHandle']];
                } catch (\Throwable $e) {
                    // Se deja en la cola: SQS lo volverá a entregar más tarde.
                    $stats['errors']++;
                    Logger::app()->error('No se pudo procesar un evento de SES', ['error' => $e->getMessage()]);
                }
            }
            if (!empty($done)) {
                $this->sqs->deleteMessageBatch(['QueueUrl' => $url, 'Entries' => $done]);
            }
            if (count($batch) < 10) {
                break;
            }
        }

        if ($stats['received'] > 0) {
            @file_put_contents(dirname(__DIR__, 2) . '/storage/ses-events.json', json_encode(['last_event_at' => time()] + $stats));
        }

        return $stats;
    }

    /**
     * Procesa el cuerpo de un mensaje de la cola (evento de EventBridge con el
     * evento de SES en "detail"). Devuelve false si se ignora.
     */
    public function handle(string $body): bool
    {
        $event = json_decode($body, true);
        if (!is_array($event)) {
            return false;
        }
        $detail = is_array($event['detail'] ?? null) ? $event['detail'] : $event;
        if (($event['source'] ?? 'aws.ses') !== 'aws.ses') {
            return false;
        }

        // Defensa adicional: solo eventos del configuration set de ESTA instalación.
        $ownSet = EmailService::configurationSet();
        $set = $detail['mail']['tags']['ses:configuration-set'][0] ?? null;
        if ($ownSet !== null && $set !== null && $set !== $ownSet) {
            Logger::app()->warning('Evento de SES de otro configuration set ignorado', ['set' => mb_substr((string) $set, 0, 64)]);

            return false;
        }

        $type = (string) ($detail['eventType'] ?? $detail['notificationType'] ?? '');
        $messageId = (string) ($detail['mail']['messageId'] ?? '');
        if ($messageId === '') {
            return false;
        }
        $recipient = $this->recipients->findEmailByExternalId($messageId);

        switch ($type) {
            case 'Delivery':
                if ($recipient !== null) {
                    $this->setStatus($recipient, 'delivered');
                }

                return true;

            case 'Bounce':
                $bounce = (array) ($detail['bounce'] ?? []);
                $permanent = ($bounce['bounceType'] ?? '') === 'Permanent';
                $kind = trim(($bounce['bounceType'] ?? '') . '/' . ($bounce['bounceSubType'] ?? ''), '/');
                foreach ((array) ($bounce['bouncedRecipients'] ?? []) as $br) {
                    $email = strtolower(trim((string) ($br['emailAddress'] ?? '')));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $diag = trim((string) ($br['diagnosticCode'] ?? ''));
                    if ($permanent) {
                        // No se le vuelve a enviar: protege la reputación de la cuenta.
                        $this->suppressions->add($email, 'bounce', mb_substr($kind . ($diag !== '' ? ' ' . $diag : ''), 0, 500));
                    }
                }
                if ($recipient !== null) {
                    if ($permanent) {
                        $this->fail($recipient, 'bounced', 'Rebote permanente (' . $kind . '): la dirección no existe o no acepta correo. Se agregó a la lista de supresión.');
                    } else {
                        $this->setStatus($recipient, 'soft_bounce');
                    }
                }
                Logger::mail()->warning('Rebote de email', ['tipo' => $kind, 'permanente' => $permanent, 'messageId' => $messageId]);

                return true;

            case 'Reject':
                if ($recipient !== null) {
                    $reason = trim((string) ($detail['reject']['reason'] ?? ''));
                    $this->fail($recipient, 'rejected', 'SES rechazó el correo' . ($reason !== '' ? ': ' . $reason : '') . '.');
                }

                return true;

            case 'Complaint':
                foreach ((array) ($detail['complaint']['complainedRecipients'] ?? []) as $cr) {
                    $email = strtolower(trim((string) ($cr['emailAddress'] ?? '')));
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->suppressions->add($email, 'complaint', 'Marcó el correo como spam');
                    }
                }
                if ($recipient !== null) {
                    $this->setStatus($recipient, 'complaint');
                }

                return true;
        }

        return false;
    }

    private function setStatus(array $r, string $status): bool
    {
        $current = (string) ($r['delivery_status'] ?? '');
        if ($current !== '' && (self::RANK[$current] ?? 0) > (self::RANK[$status] ?? 0)) {
            return false;
        }
        if ($current === $status) {
            return false;
        }
        $this->recipients->setDeliveryStatus((int) $r['id'], $status);

        return true;
    }

    /** Marca el destinatario como fallido una sola vez (no reembolsa). */
    private function fail(array $r, string $status, string $error): void
    {
        $changed = $this->setStatus($r, $status);
        if (!$changed || ($r['status'] ?? '') === 'Failed') {
            return;
        }
        $this->recipients->markFailed((int) $r['id'], $error);
        if (!$this->messages->markSentAsFailed('email', (string) $r['email'], (int) $r['campaign_id'], $error)) {
            $this->messages->log('email', (string) $r['email'], null, 'Failed', $error, (int) $r['campaign_id']);
        }
        $this->campaigns->refreshCounts((int) $r['campaign_id']);
    }
}
