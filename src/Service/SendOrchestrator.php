<?php

namespace App\Service;

use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\ContactListRepository;
use App\Repositories\ContactRepository;
use App\Repositories\JobRepository;
use App\Repositories\SenderRepository;
use App\Support\Attachments;
use App\Support\Logger;
use App\Support\Sms;

/**
 * Punto único de entrada de TODOS los envíos.
 *
 * Antes había dos caminos: los envíos directos (email/sms individuales y
 * masivos) y las campañas. Eso dejaba los envíos puntuales sin historial ni
 * estadísticas. Ahora cualquier envío se registra como campaña, así que:
 *
 *   - Todo queda con su nombre, destinatarios y resultado por destinatario.
 *   - Las métricas de Reportes cubren el 100% de la actividad.
 *   - Hay un solo procesador de envíos (CampaignService), no dos.
 *
 * El campo `origin` distingue un envío puntual ('direct') de una campaña
 * planificada ('campaign') para poder separarlos en los informes.
 */
class SendOrchestrator
{
    /** Por encima de este número no se envía dentro de la petición HTTP. */
    public const MAX_SYNC_RECIPIENTS = 100;

    /**
     * Las llamadas de voz son mucho más lentas de originar que un email/SMS
     * (hay que generar el audio con TTS y esperar la respuesta HTTP de la API
     * de voz por cada una): un límite más bajo evita que la petición del
     * compositor agote el tiempo del navegador.
     */
    public const MAX_SYNC_CALLS = 15;

    private CampaignRepository $campaigns;
    private CampaignRecipientRepository $recipients;
    private ContactListRepository $lists;
    private ContactRepository $contacts;
    private JobRepository $jobs;
    private CampaignService $service;
    private CreditService $credits;
    private SenderRepository $senders;

    public function __construct(
        ?CampaignRepository $campaigns = null,
        ?CampaignRecipientRepository $recipients = null,
        ?ContactListRepository $lists = null,
        ?ContactRepository $contacts = null,
        ?JobRepository $jobs = null,
        ?CampaignService $service = null,
        ?CreditService $credits = null,
        ?SenderRepository $senders = null
    ) {
        $this->campaigns = $campaigns ?? new CampaignRepository();
        $this->recipients = $recipients ?? new CampaignRecipientRepository();
        $this->lists = $lists ?? new ContactListRepository();
        $this->contacts = $contacts ?? new ContactRepository();
        $this->jobs = $jobs ?? new JobRepository();
        $this->service = $service ?? new CampaignService();
        $this->credits = $credits ?? new CreditService();
        $this->senders = $senders ?? new SenderRepository();
    }

    /**
     * Crea la campaña y la envía, la encola o la deja programada.
     *
     * @param array{
     *   channel:string, name?:string, subject?:?string, body:string,
     *   recipients?:array, listId?:?int, senderId?:?string, tags?:?string,
     *   scheduleType?:string, scheduledAt?:?string, expiresAt?:?string,
     *   templateId?:?int, origin?:string, attachments?:array
     * } $input
     *
     * @return array{
     *   campaign:array, mode:string, result?:array, cost:array,
     *   blocked?:array, message:string
     * }
     */
    public function dispatch(array $input): array
    {
        $channel = $input['channel'];
        if (!in_array($channel, ['email', 'sms', 'call'], true)) {
            throw new \InvalidArgumentException('Canal inválido (email, sms o call).');
        }

        $body = (string) ($input['body'] ?? '');
        if (trim($body) === '') {
            throw new \InvalidArgumentException('El contenido del mensaje es obligatorio.');
        }

        $subject = $input['subject'] ?? null;
        if ($channel === 'email' && (trim((string) $subject) === '')) {
            throw new \InvalidArgumentException('El asunto es obligatorio para email.');
        }
        if ($channel === 'sms') {
            $subject = null;
            $senderError = Sms::validateSenderId((string) ($input['senderId'] ?? ''));
            if ($senderError !== null) {
                throw new \InvalidArgumentException($senderError);
            }
        }
        if ($channel === 'call') {
            $subject = null;
            // La API de voz corta el mensaje a 1000 caracteres: se avisa antes
            // de originar ninguna llamada en vez de que fallen a mitad de lote.
            if (mb_strlen($body) > 1000) {
                throw new \InvalidArgumentException(
                    'El mensaje tiene ' . mb_strlen($body) . ' caracteres y el máximo para una '
                    . 'llamada de voz es 1000.'
                );
            }
        }

        $recipients = $this->resolveRecipients($channel, $input);
        if (empty($recipients)) {
            throw new \InvalidArgumentException(
                $channel === 'email'
                    ? 'No hay destinatarios con un correo válido.'
                    : 'No hay destinatarios con un teléfono válido.'
            );
        }

        $scheduleType = $input['scheduleType'] ?? 'now';
        $scheduledAt = null;
        if ($scheduleType === 'scheduled') {
            $scheduledAt = $this->normalizeDateTime($input['scheduledAt'] ?? '');
            if ($scheduledAt === null) {
                throw new \InvalidArgumentException('La fecha de envío no es válida.');
            }
        }

        $expiresAt = $this->normalizeDateTime($input['expiresAt'] ?? '');
        if ($expiresAt !== null && $scheduledAt !== null && $expiresAt <= $scheduledAt) {
            throw new \InvalidArgumentException('La caducidad debe ser posterior a la fecha de envío.');
        }

        $segments = $channel === 'sms' ? max(1, Sms::segments($body)) : 1;
        // Solo volumen (segmentos/destinatarios): los precios son internos y
        // no se devuelven al cliente.
        $cost = Sms::estimateCost($segments, count($recipients));

        // Saldo: el lote completo debe estar cubierto ANTES de registrar nada.
        // El cobro real se hace por envío en CampaignService (si un envío
        // falla, no se cobra). Lanza InsufficientCreditException (400/402).
        $this->credits->assertCanAfford($channel, count($recipients));

        // Email de respuesta elegido en el compositor (solo email):
        //   null  -> el de respuesta por defecto (si hay)
        //   ''    -> sin Reply-To
        //   email -> debe ser uno de los emails de respuesta registrados.
        $replyTo = $channel === 'email' ? $this->resolveReplyTo($input['replyTo'] ?? null) : null;

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = $this->autoName($channel);
        }

        $campaignId = $this->campaigns->createFull([
            'name'            => $name,
            'channel'         => $channel,
            'templateId'      => !empty($input['templateId']) ? (int) $input['templateId'] : null,
            'subject'         => $subject,
            'body'            => $body,
            'status'          => $scheduledAt !== null ? 'Scheduled' : 'Draft',
            'scheduledAt'     => $scheduledAt,
            'totalRecipients' => count($recipients),
            'senderId'        => $input['senderId'] ?? null,
            'expiresAt'       => $expiresAt,
            'tags'            => $input['tags'] ?? null,
            'segments'        => $segments,
            'costEstimate'    => null,
            'origin'          => $input['origin'] ?? 'direct',
            'voiceGender'     => $channel === 'call' ? (($input['voiceGender'] ?? 'F') === 'M' ? 'M' : 'F') : null,
            'replyTo'         => $replyTo,
        ]);

        $this->recipients->addMany($campaignId, $recipients);

        // Los adjuntos se guardan junto a la campaña: si el envío se programa o
        // se encola, lo procesa otro proceso más tarde y los temporales de la
        // petición HTTP ya no existirían.
        if (!empty($input['attachments'])) {
            Attachments::persistForCampaign($input['attachments'], $campaignId);
        }

        Logger::app()->info('Envío registrado como campaña', [
            'campaignId'    => $campaignId,
            'canal'         => $channel,
            'destinatarios' => count($recipients),
            'origen'        => $input['origin'] ?? 'direct',
            'programado'    => $scheduledAt,
        ]);

        // --- Programado: lo recogerá el proceso en segundo plano ---
        if ($scheduledAt !== null) {
            return [
                'campaign' => $this->campaigns->find($campaignId),
                'mode'     => 'scheduled',
                'cost'     => $cost,
                'message'  => 'Campaña "' . $name . '" programada para el '
                    . date('d/m/Y H:i', strtotime($scheduledAt)) . '.',
            ];
        }

        // --- Volumen alto: se encola para no agotar el tiempo de la petición ---
        $syncLimit = $channel === 'call' ? self::MAX_SYNC_CALLS : self::MAX_SYNC_RECIPIENTS;
        if (count($recipients) > $syncLimit) {
            $this->campaigns->setStatus($campaignId, 'Scheduled');
            $jobId = $this->jobs->enqueue(JobRepository::TYPE_CAMPAIGN, ['campaignId' => $campaignId]);

            return [
                'campaign' => $this->campaigns->find($campaignId),
                'mode'     => 'queued',
                'jobId'    => $jobId,
                'cost'     => $cost,
                'message'  => 'Son ' . count($recipients) . ' destinatarios: la campaña "' . $name
                    . '" se está enviando en segundo plano. Sigue su progreso en Reportes.',
            ];
        }

        // --- Envío inmediato ---
        @set_time_limit(60 + count($recipients) * 2);
        // No se le pasan los adjuntos: process() los lee ya persistidos, igual
        // que hará el dispatcher. Un solo camino, un solo comportamiento.
        $result = $this->service->process($this->campaigns->find($campaignId));

        return [
            'campaign' => $this->campaigns->find($campaignId),
            'mode'     => 'sent',
            'result'   => $result,
            'cost'     => $cost,
            'message'  => $result['failed'] === 0
                ? 'Enviado a ' . $result['sent'] . ' destinatario(s).'
                : $result['sent'] . ' enviado(s) y ' . $result['failed'] . ' fallido(s).',
        ];
    }

    /**
     * Valida el email de respuesta pedido. Solo se aceptan direcciones
     * registradas como "email de respuesta" (Verificación), para que nadie
     * pueda inyectar un Reply-To arbitrario por la API.
     */
    private function resolveReplyTo(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || $value === 'none') {
            return '';
        }
        if ($value === 'default') {
            return null;
        }

        $row = $this->senders->findByEmail($value);
        if ($row === null || ($row['type'] ?? 'sender') !== 'reply') {
            throw new \InvalidArgumentException(
                'El email de respuesta ' . $value . ' no está registrado. Agrégalo en Verificación > Email de respuesta.'
            );
        }

        return (string) $row['email'];
    }

    /** Nombre automático con el formato del compositor: SMS-2026-10-06-17:10:49 */
    private function autoName(string $channel): string
    {
        return strtoupper($channel) . '-' . date('Y-m-d-H:i:s');
    }

    /**
     * Reúne destinatarios de las tres fuentes y los deja listos para insertar,
     * conservando el contacto cuando se conoce (para los campos personalizados).
     *
     * @return array<int,array{email?:?string,phone?:?string,contact_id?:?int}>
     */
    private function resolveRecipients(string $channel, array $input): array
    {
        $raw = $input['recipients'] ?? [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $raw);
        }

        $out = [];
        $seen = [];

        // Columnas reservadas del CSV: identifican al contacto, no son variables
        // de este envío. Cualquier OTRA columna (valor, fecha_pago, placa...) se
        // guarda como variable propia de ESTE destinatario (ver `vars` abajo),
        // no en el contacto: ese dato cambia en cada envío.
        $reservedKeys = ['name', 'email', 'phone'];

        // 1) Valores escritos, pegados a mano o filas de un CSV con variables
        //    (en ese caso cada elemento es un array name/email/phone/... en vez
        //    de un string suelto).
        foreach ((array) $raw as $value) {
            if (is_array($value)) {
                $target = $channel === 'email' ? ($value['email'] ?? '') : ($value['phone'] ?? '');
                $target = trim((string) $target);
                if ($target === '') {
                    continue;
                }
                if ($channel === 'email' && filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                $key = strtolower($target);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                // Se crea (o reutiliza) el contacto para que {{nombre}} y demás
                // campos personalizados se resuelvan igual que con una lista.
                $contact = $this->contacts->firstOrCreate(
                    trim((string) ($value['name'] ?? '')) ?: null,
                    $channel === 'email' ? $target : (trim((string) ($value['email'] ?? '')) ?: null),
                    $channel === 'sms' ? $target : (trim((string) ($value['phone'] ?? '')) ?: null)
                );

                $vars = [];
                foreach ($value as $k => $v) {
                    if (in_array($k, $reservedKeys, true)) {
                        continue;
                    }
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $vars[strtolower((string) $k)] = $v;
                    }
                }

                $row = $channel === 'email'
                    ? ['email' => $target, 'contact_id' => (int) $contact['id']]
                    : ['phone' => $target, 'contact_id' => (int) $contact['id']];
                if (!empty($vars)) {
                    $row['vars'] = $vars;
                }
                $out[] = $row;
                continue;
            }

            $value = trim((string) $value, " \t\n\r\0\x0B\"'[]");
            if ($value === '') {
                continue;
            }
            $key = strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($channel === 'email') {
                if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                    $out[] = ['email' => $value];
                }
            } else {
                $out[] = ['phone' => $value];
            }
        }

        // 2) Lista guardada: se traen los contactos completos para poder
        //    personalizar el mensaje con sus datos.
        if (!empty($input['listId'])) {
            foreach ($this->lists->activeMembers((int) $input['listId']) as $contact) {
                $value = $channel === 'email' ? ($contact['email'] ?? '') : ($contact['phone'] ?? '');
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }
                $key = strtolower(trim((string) $value));
                if (isset($seen[$key])) {
                    continue;
                }
                if ($channel === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                $seen[$key] = true;

                $out[] = $channel === 'email'
                    ? ['email' => $value, 'contact_id' => (int) $contact['id']]
                    : ['phone' => $value, 'contact_id' => (int) $contact['id']];
            }
        }

        return $out;
    }

    private function normalizeDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
