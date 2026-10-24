<?php

namespace App\Controllers;

use App\Database\Connection;
use App\Repositories\CampaignRepository;
use App\Repositories\MessageRepository;
use App\Service\CreditService;
use App\Service\WhatsAppService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Panel: resumen del periodo por canal, gasto, lo que pide atención
 * (respuestas, bajas, programados, fallos) y, para administradores, el
 * estado de configuración de cada canal. Solo consultas locales (rápidas):
 * no llama a APIs externas.
 */
class DashboardController
{
    use JsonResponseTrait;

    public const CHANNELS = ['email', 'sms', 'call', 'whatsapp'];
    private const PERIODS = ['today', '7d', '30d', 'month'];

    private MessageRepository $messages;
    private CampaignRepository $campaigns;
    private ?PDO $pdo;

    public function __construct(?MessageRepository $messages = null, ?CampaignRepository $campaigns = null, ?PDO $pdo = null)
    {
        $this->messages = $messages ?? new MessageRepository();
        $this->campaigns = $campaigns ?? new CampaignRepository();
        $this->pdo = $pdo;
    }

    public function stats(Request $request, Response $response): Response
    {
        $period = (string) ($request->getQueryParams()['period'] ?? '30d');
        if (!in_array($period, self::PERIODS, true)) {
            $period = '30d';
        }
        $since = match ($period) {
            'today' => date('Y-m-d 00:00:00'),
            '7d'    => date('Y-m-d H:i:s', strtotime('-7 days')),
            'month' => date('Y-m-01 00:00:00'),
            default => date('Y-m-d H:i:s', strtotime('-30 days')),
        };

        $campaigns = $this->campaigns->all();
        $active = array_filter($campaigns, static fn (array $c): bool => in_array($c['status'], ['Scheduled', 'Sending'], true));
        $byChannel = $this->byChannel($since);

        $data = [
            'period'          => $period,
            'since'           => $since,
            // Compatibilidad con la versión anterior del panel.
            'emailsSent'      => $byChannel['email']['sent'],
            'smsSent'         => $byChannel['sms']['sent'],
            'totalCampaigns'  => count($campaigns),
            'activeCampaigns' => count($active),
            'recentCampaigns' => array_values(array_slice($campaigns, 0, 5)),
            'byChannel'       => array_values($byChannel),
            'spend'           => $this->spend($since),
            'attention'       => $this->attention($since),
        ];

        if (strtolower((string) ($request->getAttribute('user')['role'] ?? '')) === 'admin') {
            $data['channelsHealth'] = $this->channelsHealth();
        }

        return $this->jsonResponse($response, $data);
    }

    // -------------------------------------------------------------- periodo --

    /**
     * Destinatarios por canal en campañas creadas en el periodo. Se cuenta
     * sobre campaign_recipients (no messages) para que una llamada no cuente
     * dos veces (Dialing + resultado).
     *
     * @return array<string,array{channel:string,sent:int,failed:int,pending:int,total:int,deliveryRate:float}>
     */
    private function byChannel(string $since): array
    {
        $out = [];
        foreach (self::CHANNELS as $ch) {
            $out[$ch] = ['channel' => $ch, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'total' => 0, 'deliveryRate' => 0.0];
        }
        $stmt = $this->pdo()->prepare(
            "SELECT c.channel,
                    SUM(cr.status = 'Sent')   AS sent,
                    SUM(cr.status = 'Failed') AS failed,
                    SUM(cr.status IN ('Pending', 'Dialing')) AS pending,
                    COUNT(*) AS total
               FROM campaign_recipients cr
               JOIN campaigns c ON c.id = cr.campaign_id
              WHERE c.created_at >= :since
              GROUP BY c.channel"
        );
        $stmt->execute([':since' => $since]);
        foreach ($stmt->fetchAll() as $r) {
            $ch = (string) $r['channel'];
            if (!isset($out[$ch])) {
                continue;
            }
            $sent = (int) $r['sent'];
            $failed = (int) $r['failed'];
            $out[$ch] = [
                'channel'      => $ch,
                'sent'         => $sent,
                'failed'       => $failed,
                'pending'      => (int) $r['pending'],
                'total'        => (int) $r['total'],
                'deliveryRate' => $sent + $failed > 0 ? round($sent * 100 / ($sent + $failed), 1) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * Gasto neto (débitos menos reembolsos) del periodo por canal, saldo y
     * proyección según el consumo de los últimos 7 días. No expone precios.
     */
    private function spend(string $since): array
    {
        $byChannel = array_fill_keys(self::CHANNELS, 0.0);
        $stmt = $this->pdo()->prepare(
            "SELECT channel, -SUM(amount) AS net FROM credit_transactions
              WHERE type IN ('debit', 'refund') AND created_at >= :since GROUP BY channel"
        );
        $stmt->execute([':since' => $since]);
        foreach ($stmt->fetchAll() as $r) {
            $ch = str_starts_with((string) $r['channel'], 'whatsapp') ? 'whatsapp' : (string) $r['channel'];
            if (isset($byChannel[$ch])) {
                $byChannel[$ch] += max(0.0, (float) $r['net']);
            }
        }

        $week = (float) $this->pdo()->query(
            "SELECT COALESCE(-SUM(amount), 0) FROM credit_transactions
              WHERE type IN ('debit', 'refund') AND created_at >= NOW() - INTERVAL 7 DAY"
        )->fetchColumn();
        $dailyAvg = max(0.0, $week) / 7;

        $credits = new CreditService($this->pdo());
        $account = $credits->account();
        $balance = $account['balance'];
        $percent = $account['reference'] > 0 ? (int) round(min(100, $balance * 100 / $account['reference'])) : 0;

        return [
            'total'     => round(array_sum($byChannel), 2),
            'byChannel' => array_map(static fn ($v) => round($v, 2), $byChannel),
            'balance'   => $balance,
            'dailyAvg'  => round($dailyAvg, 2),
            // null si no hubo consumo en la última semana (no se puede proyectar).
            'daysLeft'  => $dailyAvg > 0 ? (int) floor($balance / $dailyAvg) : null,
            'low'       => $account['reference'] > 0 && $percent < 20,
        ];
    }

    // ------------------------------------------------------------- atención --

    private function attention(string $since): array
    {
        $pdo = $this->pdo();

        // Respuestas (SMS doble vía y WhatsApp), sin contar las bajas.
        $replies = [];
        $count = 0;
        foreach ([
            ['sms', "SELECT phone, message, received_at FROM sms_replies WHERE is_optout = 0 AND received_at >= :since ORDER BY id DESC LIMIT 5",
                    "SELECT COUNT(*) FROM sms_replies WHERE is_optout = 0 AND received_at >= :since"],
            ['whatsapp', "SELECT phone, message, received_at FROM whatsapp_inbound WHERE is_optout = 0 AND received_at >= :since ORDER BY id DESC LIMIT 5",
                         "SELECT COUNT(*) FROM whatsapp_inbound WHERE is_optout = 0 AND received_at >= :since"],
        ] as [$channel, $listSql, $countSql]) {
            $c = $pdo->prepare($countSql);
            $c->execute([':since' => $since]);
            $count += (int) $c->fetchColumn();
            $s = $pdo->prepare($listSql);
            $s->execute([':since' => $since]);
            foreach ($s->fetchAll() as $r) {
                $replies[] = [
                    'channel'    => $channel,
                    'phone'      => (string) $r['phone'],
                    'message'    => mb_substr((string) $r['message'], 0, 140),
                    'receivedAt' => (string) $r['received_at'],
                ];
            }
        }
        usort($replies, static fn ($a, $b) => strcmp($b['receivedAt'], $a['receivedAt']));

        $q = static function (string $sql) use ($pdo, $since): int {
            $s = $pdo->prepare($sql);
            $s->execute([':since' => $since]);

            return (int) $s->fetchColumn();
        };

        $upcoming = $pdo->query(
            "SELECT id, name, channel, scheduled_at, total_recipients FROM campaigns
              WHERE status = 'Scheduled' AND scheduled_at IS NOT NULL AND scheduled_at >= NOW()
              ORDER BY scheduled_at ASC LIMIT 5"
        )->fetchAll();

        // Campañas del periodo con fallos y su motivo más frecuente.
        $failedStmt = $pdo->prepare(
            "SELECT id, name, channel, status, sent_count, failed_count, total_recipients, created_at FROM campaigns
              WHERE created_at >= :since AND failed_count > 0 AND origin <> 'test'
              ORDER BY created_at DESC LIMIT 5"
        );
        $failedStmt->execute([':since' => $since]);
        $topError = $pdo->prepare(
            "SELECT error, COUNT(*) n FROM campaign_recipients
              WHERE campaign_id = :id AND status = 'Failed' AND error IS NOT NULL
              GROUP BY error ORDER BY n DESC LIMIT 1"
        );
        $failed = [];
        foreach ($failedStmt->fetchAll() as $c) {
            $topError->execute([':id' => $c['id']]);
            $e = $topError->fetch();
            $failed[] = [
                'id'       => (int) $c['id'],
                'name'     => (string) $c['name'],
                'channel'  => (string) $c['channel'],
                'status'   => (string) $c['status'],
                'sent'     => (int) $c['sent_count'],
                'failed'   => (int) $c['failed_count'],
                'total'    => (int) $c['total_recipients'],
                'topError' => $e ? mb_substr((string) $e['error'], 0, 160) : null,
            ];
        }

        return [
            'replies'         => $count,
            'recentReplies'   => array_slice($replies, 0, 5),
            'optOuts'         => $q('SELECT COUNT(*) FROM contacts WHERE unsubscribed_at >= :since'),
            'emailSuppressed' => $q('SELECT COUNT(*) FROM suppressions WHERE created_at >= :since'),
            'upcoming'        => array_map(static fn ($c) => [
                'id'          => (int) $c['id'],
                'name'        => (string) $c['name'],
                'channel'     => (string) $c['channel'],
                'scheduledAt' => (string) $c['scheduled_at'],
                'recipients'  => (int) $c['total_recipients'],
            ], $upcoming),
            'failedCampaigns' => $failed,
        ];
    }

    // ------------------------------------------------------ estado (admin) --

    /**
     * Semáforo de configuración por canal: ok | warning | off. Solo usa datos
     * locales (.env, BD y la caché de estado de WhatsApp).
     */
    private function channelsHealth(): array
    {
        $env = static fn (string $k): string => trim((string) ($_ENV[$k] ?? ''), " \"'");
        $pdo = $this->pdo();

        // Email: dominio verificado en SES y remitente por defecto verificado.
        $domains = (int) $pdo->query("SELECT COUNT(*) FROM domains WHERE verification_status = 'Success'")->fetchColumn();
        $sender = (int) $pdo->query("SELECT COUNT(*) FROM senders WHERE type = 'sender' AND verification_status = 'Success'")->fetchColumn();
        $aws = $env('AWS_ACCESS_KEY_ID') !== '' && $env('AWS_SECRET_ACCESS_KEY') !== '';
        $email = !$aws
            ? ['status' => 'off', 'message' => 'Faltan las credenciales de envío de email.']
            : ($domains > 0 && $sender > 0
                ? ['status' => 'ok', 'message' => "Dominio verificado ({$domains}) y remitente listo."]
                : ['status' => 'warning', 'message' => $domains === 0 ? 'No hay un dominio verificado (ver Verificación).' : 'No hay un remitente verificado.']);

        // SMS (IT Cloud)
        $sms = $env('ITCLOUD_SMS_EMAIL') !== '' && $env('ITCLOUD_SMS_TOKEN') !== ''
            ? ['status' => 'ok', 'message' => 'Proveedor de SMS configurado.']
            : ['status' => 'off', 'message' => 'Proveedor de SMS sin configurar.'];

        // Voz
        $voice = $env('VOICE_API_URL') !== '' && $env('VOICE_API_KEY') !== ''
            ? ['status' => 'ok', 'message' => 'API de voz configurada.']
            : ['status' => 'off', 'message' => 'API de voz sin configurar.'];

        // WhatsApp: configuración + último estado consultado + plantillas.
        if (!(new WhatsAppService())->isConfigured()) {
            $wa = ['status' => 'off', 'message' => 'Línea sin activar.'];
        } else {
            $cacheFile = dirname(__DIR__, 2) . '/storage/whatsapp-status.json';
            $cache = is_file($cacheFile) ? (json_decode((string) @file_get_contents($cacheFile), true) ?: []) : [];
            $approved = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_templates WHERE status = 'APPROVED'")->fetchColumn();
            $pending = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_templates WHERE status = 'PENDING'")->fetchColumn();
            $healthy = (bool) ($cache['health']['healthy'] ?? false);
            $notLive = stripos((string) ($cache['health']['message'] ?? ''), 'not live') !== false;
            $quality = $cache['ratings']['quality'] ?? null;
            $tpl = "{$approved} plantilla(s) aprobada(s)" . ($pending ? ", {$pending} en revisión" : '');
            if (!isset($cache['health'])) {
                $wa = ['status' => 'warning', 'message' => 'Estado sin consultar (abre WhatsApp → Línea). ' . $tpl . '.'];
            } elseif ($notLive) {
                $wa = ['status' => 'warning', 'message' => 'Pendiente de registrar el número.'];
            } elseif (!$healthy || $quality === 'RED') {
                $wa = ['status' => 'warning', 'message' => ($healthy ? 'Calidad del número baja. ' : 'La línea reporta un problema. ') . $tpl . '.'];
            } elseif ($approved === 0) {
                $wa = ['status' => 'warning', 'message' => 'Línea activa, sin plantillas aprobadas.'];
            } else {
                $wa = ['status' => 'ok', 'message' => 'Línea activa. ' . $tpl . '.'];
            }
            if ($env('WHATSAPP_WEBHOOK_URL') === '' && $wa['status'] === 'ok') {
                $wa = ['status' => 'warning', 'message' => $wa['message'] . ' Sin webhook: no llegan estados ni respuestas.'];
            }
        }

        return [
            ['channel' => 'email', 'label' => 'Email'] + $email,
            ['channel' => 'sms', 'label' => 'SMS'] + $sms,
            ['channel' => 'call', 'label' => 'Llamadas'] + $voice,
            ['channel' => 'whatsapp', 'label' => 'WhatsApp'] + $wa,
        ];
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connection::get();
    }
}
