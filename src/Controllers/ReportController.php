<?php

namespace App\Controllers;

use App\Repositories\CampaignRepository;
use App\Repositories\MessageRepository;
use App\Repositories\ReportRepository;
use App\Repositories\SuppressionRepository;
use App\Support\Csv;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Métricas e historial reales, calculados sobre la base de datos, y
 * exportación a CSV (Excel).
 */
class ReportController
{
    use JsonResponseTrait;

    public const STATUS_LABELS = [
        'Draft' => 'Borrador', 'Scheduled' => 'Programada', 'Sending' => 'Enviando', 'Completed' => 'Completada',
        'Failed' => 'Fallida', 'Expired' => 'Caducada',
    ];
    public const RECIPIENT_LABELS = [
        'Pending' => 'Pendiente', 'Sent' => 'Enviado', 'Failed' => 'Fallido', 'Dialing' => 'Marcando',
    ];
    public const CHANNEL_LABELS = ['email' => 'Email', 'sms' => 'SMS', 'call' => 'Llamada', 'whatsapp' => 'WhatsApp'];
    public const DELIVERY_LABELS = [
        'accepted' => 'Aceptado', 'sent' => 'Enviado', 'delivered' => 'Entregado', 'read' => 'Leído', 'failed' => 'Fallido',
        'bounced' => 'Rebotado', 'soft_bounce' => 'Rebote temporal', 'rejected' => 'Rechazado', 'complaint' => 'Queja',
    ];

    private const EXPORT_LIMIT = 50000;

    private MessageRepository $messages;
    private CampaignRepository $campaigns;
    private SuppressionRepository $suppressions;
    private ?ReportRepository $reports;

    public function __construct(
        ?MessageRepository $messages = null,
        ?CampaignRepository $campaigns = null,
        ?SuppressionRepository $suppressions = null,
        ?ReportRepository $reports = null
    ) {
        $this->messages = $messages ?? new MessageRepository();
        $this->campaigns = $campaigns ?? new CampaignRepository();
        $this->suppressions = $suppressions ?? new SuppressionRepository();
        $this->reports = $reports;
    }

    /** Historial de mensajes paginado y filtrable. */
    public function messages(Request $request, Response $response): Response
    {
        $p = Paginator::fromRequest($request);
        $q = $request->getQueryParams();

        $result = $this->messages->paginate(
            $p->perPage,
            $p->offset,
            $this->param($q, 'channel'),
            $this->param($q, 'status'),
            $this->date($q, 'from'),
            $this->date($q, 'to'),
            $this->param($q, 'search')
        );

        return $this->jsonResponse($response, [
            'messages'   => $result['items'],
            'pagination' => $p->meta($result['total']),
        ]);
    }

    /**
     * Resumen del rango (days o from/to): totales y comparación con el
     * periodo anterior, por canal, gasto, serie diaria, embudo de WhatsApp,
     * llamadas, respuestas, motivos de fallo y rendimiento por campaña.
     */
    public function summary(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        [$since, $until, $days, $from, $to] = $this->range($q);
        $prevSince = date('Y-m-d H:i:s', strtotime($since) - (strtotime($until) - strtotime($since)));

        $r = $this->reports();
        $byChannel = $r->byChannel($since, $until);
        $prevByChannel = $r->byChannel($prevSince, $since);
        $spend = $r->spend($since, $until);
        $prevSpend = $r->spend($prevSince, $since);

        foreach ($byChannel as $ch => &$c) {
            $c['spend'] = $spend['byChannel'][$ch] ?? 0.0;
        }
        unset($c);
        foreach ($spend['byChannel'] as $ch => $amount) {
            if (!isset($byChannel[$ch]) && $amount > 0) {
                $byChannel[$ch] = ['channel' => $ch, 'sent' => 0, 'failed' => 0, 'total' => 0, 'deliveryRate' => 0.0, 'spend' => $amount];
            }
        }

        $totals = self::totals($byChannel);
        $prevTotals = self::totals($prevByChannel);
        $replies = $r->repliesSummary($since, $until);

        // Rendimiento de las campañas del rango, con su gasto y respuestas.
        $search = mb_strtolower((string) ($this->param($q, 'search') ?? ''));
        $inRange = array_values(array_filter(
            $r->campaignsInRange($since, $until, 500),
            static fn (array $c) => (int) $c['total_recipients'] > 0
                && ($search === '' || str_contains(mb_strtolower($c['name'] . ' ' . ($c['tags'] ?? '')), $search))
        ));
        $top = array_slice($inRange, 0, 20);
        $stats = $r->campaignStats(array_column($top, 'id'));
        $performance = array_map(static fn (array $c) => self::performanceRow($c, $stats[(int) $c['id']] ?? []), $top);

        $statusCounts = [];
        foreach ($inRange as $c) {
            $statusCounts[$c['status']] = ($statusCounts[$c['status']] ?? 0) + 1;
        }

        return $this->jsonResponse($response, [
            'range'  => ['from' => $from, 'to' => $to, 'days' => $days, 'since' => $since, 'until' => $until],
            'totals' => $totals + ['spend' => $spend['total'], 'refunded' => $spend['refunded']],
            'previous' => $prevTotals + ['spend' => $prevSpend['total']],
            'byChannel'           => array_values($byChannel),
            'dailySeries'         => $r->dailySeries($since, $until),
            'whatsappFunnel'      => $r->whatsappFunnel($since, $until),
            'emailDelivery'       => $r->emailDelivery($since, $until) + ['tracking' => \App\Service\SesEventService::isConfigured()],
            'callOutcomes'        => $r->callOutcomes($since, $until),
            'replies'             => $replies,
            'failureReasons'      => $r->failureReasons($since, $until),
            'campaigns'           => ['total' => count($inRange), 'byStatus' => $statusCounts],
            'campaignPerformance' => $performance,
            'topFailures'         => $this->messages->topFailures(10),
            'suppressions'        => $this->suppressions->countsByReason(),
        ]);
    }

    /** Listado de la lista de supresión (rebotes y quejas). */
    public function suppressions(Request $request, Response $response): Response
    {
        $p = Paginator::fromRequest($request);
        $result = $this->suppressions->paginate($p->perPage, $p->offset);

        return $this->jsonResponse($response, [
            'suppressions' => $result['items'],
            'pagination'   => $p->meta($result['total']),
        ]);
    }

    /** Quita una dirección de la lista de supresión (tras corregir el problema). */
    public function removeSuppression(Request $request, Response $response, array $args): Response
    {
        $email = urldecode((string) ($args['email'] ?? ''));

        if (!$this->suppressions->remove($email)) {
            return $this->jsonResponse($response, ['error' => 'La dirección no está en la lista'], 404);
        }

        return $this->jsonResponse($response, ['success' => true, 'email' => $email]);
    }

    // ------------------------------------------------------------ exportar --

    /** Historial filtrado (mismos filtros que /messages) en CSV. */
    public function exportMessages(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $result = $this->messages->paginate(
            self::EXPORT_LIMIT,
            0,
            $this->param($q, 'channel'),
            $this->param($q, 'status'),
            $this->date($q, 'from'),
            $this->date($q, 'to'),
            $this->param($q, 'search')
        );
        $rows = (function () use ($result) {
            foreach ($result['items'] as $m) {
                yield [
                    $m['created_at'],
                    self::CHANNEL_LABELS[$m['channel']] ?? $m['channel'],
                    $m['recipient'],
                    $m['subject'],
                    self::RECIPIENT_LABELS[$m['status']] ?? $m['status'],
                    $m['error'],
                    $m['campaign_id'],
                ];
            }
        })();

        return Csv::response($response, 'historial-' . date('Ymd-His') . '.csv',
            ['Fecha', 'Canal', 'Destinatario', 'Asunto/Plantilla', 'Estado', 'Error', 'ID campaña'], $rows);
    }

    /** Campañas del rango con sus métricas, en CSV. */
    public function exportCampaigns(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        [$since, $until] = $this->range($q);
        $r = $this->reports();
        $list = $r->campaignsInRange($since, $until, 5000);
        $stats = [];
        foreach (array_chunk(array_column($list, 'id'), 500) as $chunk) {
            $stats += $r->campaignStats($chunk);
        }
        $rows = (function () use ($list, $stats) {
            foreach ($list as $c) {
                $p = self::performanceRow($c, $stats[(int) $c['id']] ?? []);
                yield [
                    $p['id'], $p['name'], self::CHANNEL_LABELS[$p['channel']] ?? $p['channel'],
                    self::STATUS_LABELS[$p['status']] ?? $p['status'], $c['created_at'], $c['created_by'] ?? null,
                    $c['tags'] ?? null, $p['total'], $p['sent'], $p['failed'], $p['deliveryRate'] . '%',
                    $p['funnel']['delivered'] ?? null, $p['funnel']['read'] ?? null,
                    $p['calls']['answered'] ?? null, $p['replies'], $p['optOuts'], (float) $p['cost'], (float) $p['refunded'],
                ];
            }
        })();

        return Csv::response($response, 'campanas-' . substr($since, 0, 10) . '-a-' . date('Y-m-d', strtotime($until) - 1) . '.csv', [
            'ID', 'Campaña', 'Canal', 'Estado', 'Creada', 'Creada por', 'Etiquetas', 'Destinatarios', 'Enviados',
            'Fallidos', 'Entrega', 'WhatsApp entregados', 'WhatsApp leídos', 'Llamadas contestadas', 'Respuestas',
            'Bajas', 'Gasto', 'Reembolsado',
        ], $rows);
    }

    // ------------------------------------------------------------- interno --

    /** @return array<string,mixed> */
    public static function performanceRow(array $c, array $s): array
    {
        $total = (int) $c['total_recipients'];
        $sent = (int) $c['sent_count'];
        $failed = (int) $c['failed_count'];

        return [
            'id'           => (int) $c['id'],
            'name'         => (string) $c['name'],
            'channel'      => (string) $c['channel'],
            'status'       => (string) $c['status'],
            'total'        => $total,
            'sent'         => $sent,
            'failed'       => $failed,
            'deliveryRate' => ReportRepository::rate($sent, $sent + $failed),
            'createdAt'    => (string) $c['created_at'],
            'createdBy'    => $c['created_by'] ?? null,
            'cost'         => (float) ($s['cost'] ?? 0),
            'refunded'     => (float) ($s['refunded'] ?? 0),
            'replies'      => (int) ($s['replies'] ?? 0),
            'optOuts'      => (int) ($s['optOuts'] ?? 0),
            'responseRate' => ReportRepository::rate((int) ($s['replies'] ?? 0), $sent),
            'funnel'       => $s['funnel'] ?? null,
            'calls'        => $s['calls'] ?? null,
        ];
    }

    private static function totals(array $byChannel): array
    {
        $sent = array_sum(array_column($byChannel, 'sent'));
        $failed = array_sum(array_column($byChannel, 'failed'));

        return [
            'sent'         => $sent,
            'failed'       => $failed,
            'total'        => $sent + $failed,
            'deliveryRate' => ReportRepository::rate($sent, $sent + $failed),
        ];
    }

    /**
     * Rango del informe: from/to (Y-m-d, ambos incluidos) o los últimos `days`.
     *
     * @return array{0:string,1:string,2:int,3:?string,4:?string} since, until (exclusivo), days, from, to
     */
    public function range(array $q): array
    {
        $from = $this->date($q, 'from');
        $to = $this->date($q, 'to');
        if ($from !== null || $to !== null) {
            $to ??= date('Y-m-d');
            $from ??= date('Y-m-d', strtotime($to . ' -29 days'));
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            // Máximo 2 años por consulta.
            if (strtotime($to) - strtotime($from) > 730 * 86400) {
                $from = date('Y-m-d', strtotime($to . ' -730 days'));
            }
            $since = $from . ' 00:00:00';
            $until = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
            $days = (int) round((strtotime($until) - strtotime($since)) / 86400);

            return [$since, $until, $days, $from, $to];
        }

        $days = max(1, min(365, (int) ($q['days'] ?? 30)));
        $until = date('Y-m-d 00:00:00', strtotime('tomorrow'));
        $since = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . ' days'));

        return [$since, $until, $days, null, null];
    }

    private function date(array $q, string $key): ?string
    {
        $v = $this->param($q, $key);
        if ($v === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));

        return checkdate($m, $d, $y) ? $v : null;
    }

    private function param(array $query, string $key): ?string
    {
        $value = trim((string) ($query[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    private function reports(): ReportRepository
    {
        return $this->reports ??= new ReportRepository();
    }
}
