<?php

namespace App\Service;

use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\ContactRepository;
use App\Repositories\MessageRepository;
use App\Support\Attachments;
use App\Support\Sms;

/**
 * Procesamiento de campañas compartido por "enviar ahora" (SendOrchestrator),
 * por la cola de trabajos y por el dispatcher de campañas programadas
 * (bin/dispatch-campaigns.php).
 *
 * Como ahora TODO envío es una campaña, este es el único lugar donde se llama
 * a EmailService/SmsService: así el registro por destinatario, los campos
 * personalizados y la supresión se aplican igual en los tres caminos.
 */
class CampaignService
{
    private CampaignRepository $campaigns;
    private CampaignRecipientRepository $recipients;
    private EmailService $email;
    private SmsService $sms;
    private MessageRepository $messages;
    private ContactRepository $contacts;
    private ?VoiceCallService $voiceCall;

    /** Error registrado en los destinatarios que no se enviaron por falta de saldo. */
    public const NO_CREDIT_ERROR = 'Saldo insuficiente: no se envió. Recarga y vuelve a enviar.';

    private ?CreditService $creditService;

    /** Caché de contactos dentro de un mismo proceso de campaña. */
    private array $contactCache = [];

    public function __construct(
        ?CampaignRepository $campaigns = null,
        ?CampaignRecipientRepository $recipients = null,
        ?EmailService $email = null,
        ?SmsService $sms = null,
        ?MessageRepository $messages = null,
        ?ContactRepository $contacts = null,
        ?VoiceCallService $voiceCall = null,
        ?CreditService $credits = null
    ) {
        $this->creditService = $credits;
        $this->campaigns = $campaigns ?? new CampaignRepository();
        $this->recipients = $recipients ?? new CampaignRecipientRepository();
        $this->email = $email ?? new EmailService();
        $this->sms = $sms ?? new SmsService();
        $this->messages = $messages ?? new MessageRepository();
        $this->contacts = $contacts ?? new ContactRepository();
        $this->voiceCall = $voiceCall;
    }

    /**
     * Envía todos los destinatarios Pending de una campaña y actualiza estados.
     *
     * @param array<int,array{path:string,name:string}> $attachments Si se omite,
     *        se leen los adjuntos guardados de la campaña.
     * @return array{sent:int, failed:int, skipped:int, status:string}
     */
    public function process(array $campaign, array $attachments = []): array
    {
        $id = (int) $campaign['id'];
        $channel = $campaign['channel'];
        $subject = (string) ($campaign['subject'] ?? '');
        $body = (string) ($campaign['body'] ?? '');
        $senderId = $campaign['sender_id'] ?? null;

        // Caducidad: si la fecha ya pasó, entregar el mensaje sería peor que no
        // hacerlo (ofertas vencidas, códigos expirados). Se marca y se sale.
        if ($this->hasExpired($campaign)) {
            $this->campaigns->setStatus($id, 'Expired');

            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'status' => 'Expired'];
        }

        $this->campaigns->setStatus($id, 'Sending');

        if (empty($attachments) && $channel === 'email') {
            $attachments = Attachments::forCampaign($id);
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $isFirst = true;
        $outOfCredit = false;
        // null = Reply-To por defecto; '' = ninguno; email = el elegido.
        $replyTo = array_key_exists('reply_to', $campaign) ? $campaign['reply_to'] : null;

        foreach ($this->recipients->pending($id) as $r) {
            // Sin saldo: los destinatarios restantes no se envían ni se cobran.
            if ($outOfCredit) {
                $this->recipients->markFailed((int) $r['id'], self::NO_CREDIT_ERROR);
                $failed++;
                continue;
            }

            $target = $channel === 'email' ? ($r['email'] ?? '') : ($r['phone'] ?? '');

            if ($target === null || trim((string) $target) === '') {
                $this->recipients->markFailed(
                    (int) $r['id'],
                    'Destinatario sin ' . ($channel === 'email' ? 'email' : 'teléfono')
                );
                $failed++;
                continue;
            }

            $contact = $this->contactFor($r);

            // Quien se dio de baja no vuelve a recibir envíos. No es un fallo:
            // se registra aparte para no ensuciar la tasa de error.
            if ($contact !== null && !empty($contact['unsubscribed_at'])) {
                $this->recipients->markFailed((int) $r['id'], 'El contacto se dio de baja.');
                $skipped++;
                continue;
            }

            $vars = $this->varsFor($r);
            $personalBody = ($contact !== null || !empty($vars)) ? Sms::renderFields($body, $contact ?? [], $vars) : $body;
            $personalSubject = ($contact !== null || !empty($vars)) ? Sms::renderFields($subject, $contact ?? [], $vars) : $subject;

            // Entre llamadas de voz se espera un poco: la API externa origina
            // vía AMI y no conviene saturarla con originates simultáneos. No
            // aplica a la primera llamada del lote.
            if ($channel === 'call' && !$isFirst) {
                usleep(max(0, (int) ($_ENV['VOICE_CALL_THROTTLE_MS'] ?? 1500)) * 1000);
            }
            $isFirst = false;

            // Cobro por envío, ANTES de enviar (atómico; ver CreditService).
            // Si el envío falla después, se reembolsa.
            if (!$this->credits()->debit($channel, $id, (string) $target)) {
                $outOfCredit = true;
                $this->recipients->markFailed((int) $r['id'], self::NO_CREDIT_ERROR);
                $this->messages->log($channel, (string) $target, $channel === 'email' ? $personalSubject : null, 'Failed', self::NO_CREDIT_ERROR, $id);
                $failed++;
                continue;
            }

            try {
                if ($channel === 'email') {
                    $this->email->send($target, $personalSubject, $personalBody, null, $attachments, $replyTo);
                    $this->recipients->markSent((int) $r['id']);
                    $this->messages->log($channel, $target, $personalSubject, 'Sent', null, $id);
                    $sent++;
                } elseif ($channel === 'call') {
                    $gender = (string) ($campaign['voice_gender'] ?? 'F');
                    $result = $this->voice()->call($target, $personalBody, $gender);
                    // No se marca Sent/Failed todavía: la llamada se originó
                    // pero Asterisk aún no ha terminado de marcar. El resultado
                    // real llega después, cuando el dispatcher consulte su
                    // estado (ver CampaignRecipientRepository::dueForCallStatus()).
                    $this->recipients->markDialing((int) $r['id'], $result['callId']);
                    $this->messages->log($channel, $target, null, 'Dialing', null, $id);
                    $sent++;
                } else {
                    $this->sms->send($target, $personalBody, $senderId, $campaign['sms_type'] ?? null);
                    $this->recipients->markSent((int) $r['id']);
                    $this->messages->log($channel, $target, null, 'Sent', null, $id);
                    $sent++;
                }
            } catch (\Throwable $e) {
                // No salió: se devuelve lo cobrado.
                try {
                    $this->credits()->refund($channel, $id, (string) $target, 'Envío fallido');
                } catch (\Throwable $refundError) {
                    \App\Support\Logger::app()->error('No se pudo reembolsar un envío fallido', [
                        'campaignId' => $id,
                        'to'         => $target,
                        'error'      => $refundError->getMessage(),
                    ]);
                }
                $this->recipients->markFailed((int) $r['id'], $e->getMessage());
                $this->messages->log($channel, $target, $channel === 'email' ? $personalSubject : null, 'Failed', $e->getMessage(), $id);
                $failed++;
            }
        }

        $this->campaigns->refreshCounts($id);

        // Las llamadas de voz quedan "Dialing" hasta que se resuelvan (ver
        // resolveCallStatuses(), llamado por el dispatcher): la campaña se
        // deja "Sending" en vez de "Completed" mientras alguna siga pendiente.
        if ($channel === 'call' && $this->recipients->countDialing($id) > 0) {
            return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'status' => 'Sending'];
        }

        // Si no se entregó ni uno y hubo fallos, la campaña NO está "completada":
        // marcarla como Completed ocultaba errores de configuración (credenciales
        // inválidas, remitente sin verificar, sandbox de SES...).
        $status = ($sent === 0 && $failed > 0) ? 'Failed' : 'Completed';
        $this->campaigns->setStatus($id, $status);

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'status' => $status];
    }

    /**
     * Consulta la API de voz por cada llamada que ya lleva un rato originada
     * y actualiza su estado final. Lo invoca el dispatcher en cada pasada,
     * no esta clase directamente: dar tiempo a que contesten es tan
     * importante como consultar.
     *
     * @return array{resolved:int, stillDialing:int}
     */
    public function resolveCallStatuses(int $minSecondsAgo = 20, int $limit = 50): array
    {
        $resolved = 0;
        $pending = $this->recipients->dueForCallStatus($minSecondsAgo, $limit);

        foreach ($pending as $r) {
            $callId = (string) ($r['call_id'] ?? '');
            if ($callId === '') {
                continue;
            }

            $status = $this->voice()->status($callId);

            if (!in_array($status, VoiceCallService::FINAL_STATUSES, true)) {
                // 'ringing' o 'desconocido': todavía no hay nada definitivo.
                // Si lleva mucho tiempo así, se trata como sin respuesta para
                // no dejar la campaña "Sending" indefinidamente.
                $staleMinutes = (strtotime('now') - strtotime((string) $r['dialed_at'])) / 60;
                if ($staleMinutes < 10) {
                    continue;
                }
                $status = 'rechazado';
            }

            $campaignId = (int) $r['campaign_id'];

            if ($status === 'answered') {
                $this->recipients->markSent((int) $r['id']);
                $this->messages->log('call', (string) $r['phone'], null, 'Sent', null, $campaignId);
            } else {
                // busy / rechazado: no contestaron. Se registra el motivo
                // real en vez de un genérico "Failed" sin explicación.
                $label = $status === 'busy' ? 'Línea ocupada.' : 'No contestó la llamada.';
                $this->recipients->markFailed((int) $r['id'], $label);
                $this->messages->log('call', (string) $r['phone'], null, 'Failed', $label, $campaignId);
            }
            $resolved++;

            $this->campaigns->refreshCounts($campaignId);
            if ($this->recipients->countDialing($campaignId) === 0) {
                $fresh = $this->campaigns->find($campaignId);
                $failedCount = (int) ($fresh['failed_count'] ?? 0);
                $sentCount = (int) ($fresh['sent_count'] ?? 0);
                $finalStatus = ($sentCount === 0 && $failedCount > 0) ? 'Failed' : 'Completed';
                $this->campaigns->setStatus($campaignId, $finalStatus);
            }
        }

        return ['resolved' => $resolved, 'stillDialing' => count($pending) - $resolved];
    }

    private function voice(): VoiceCallService
    {
        return $this->voiceCall ??= new VoiceCallService();
    }

    private function credits(): CreditService
    {
        return $this->creditService ??= new CreditService();
    }

    /** Variables propias de este destinatario (columnas extra del CSV), si las hay. */
    private function varsFor(array $recipient): array
    {
        $raw = $recipient['vars_json'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function hasExpired(array $campaign): bool
    {
        $expires = $campaign['expires_at'] ?? null;
        if ($expires === null || $expires === '') {
            return false;
        }

        $ts = strtotime((string) $expires);

        return $ts !== false && $ts < time();
    }

    /**
     * Datos del contacto para personalizar el mensaje. Añade `unsubscribe_url`
     * para que {{url_baja}} resuelva a un enlace único por contacto.
     */
    private function contactFor(array $recipient): ?array
    {
        $contactId = $recipient['contact_id'] ?? null;
        if ($contactId === null) {
            return null;
        }
        $contactId = (int) $contactId;

        if (array_key_exists($contactId, $this->contactCache)) {
            return $this->contactCache[$contactId];
        }

        try {
            $contact = $this->contacts->find($contactId);
        } catch (\Throwable $e) {
            $contact = null;
        }

        if ($contact !== null) {
            try {
                $token = $this->contacts->ensureUnsubscribeToken($contactId);
                if ($token !== null) {
                    $contact['unsubscribe_token'] = $token;
                    $contact['unsubscribe_url'] = self::unsubscribeUrl($token);
                }
            } catch (\Throwable $e) {
                // Sin token el mensaje se envía igual, solo sin enlace de baja.
            }
        }

        return $this->contactCache[$contactId] = $contact;
    }

    public static function unsubscribeUrl(string $token): string
    {
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');

        return $base . '/unsubscribe/' . $token;
    }
}
