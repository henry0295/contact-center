<?php

namespace App\Controllers;

use App\Repositories\CampaignRepository;
use App\Repositories\MessageRepository;
use App\Repositories\SuppressionRepository;
use App\Support\Paginator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Métricas e historial reales, calculados sobre la base de datos.
 * Sustituye a los datos inventados que tenía el módulo de Reportes.
 */
class ReportController
{
    use JsonResponseTrait;

    private MessageRepository $messages;
    private CampaignRepository $campaigns;
    private SuppressionRepository $suppressions;

    public function __construct(
        ?MessageRepository $messages = null,
        ?CampaignRepository $campaigns = null,
        ?SuppressionRepository $suppressions = null
    ) {
        $this->messages = $messages ?? new MessageRepository();
        $this->campaigns = $campaigns ?? new CampaignRepository();
        $this->suppressions = $suppressions ?? new SuppressionRepository();
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
            $this->param($q, 'from'),
            $this->param($q, 'to'),
            $this->param($q, 'search')
        );

        return $this->jsonResponse($response, [
            'messages'   => $result['items'],
            'pagination' => $p->meta($result['total']),
        ]);
    }

    /**
     * Resumen para el panel de Reportes: totales por canal, tasas de entrega,
     * serie diaria, rendimiento por campaña y destinatarios problemáticos.
     */
    public function summary(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $from = $this->param($q, 'from');
        $to = $this->param($q, 'to');
        $days = (int) ($q['days'] ?? 30);
        $days = max(1, min(365, $days));

        $totals = $this->messages->totalsByChannelAndStatus($from, $to);

        // Se reorganiza por canal para que el cliente no tenga que calcular.
        $byChannel = [];
        foreach ($totals as $row) {
            $channel = $row['channel'];
            if (!isset($byChannel[$channel])) {
                $byChannel[$channel] = ['channel' => $channel, 'sent' => 0, 'failed' => 0, 'total' => 0];
            }
            if ($row['status'] === 'Sent') {
                $byChannel[$channel]['sent'] += $row['total'];
            } elseif ($row['status'] === 'Failed') {
                $byChannel[$channel]['failed'] += $row['total'];
            }
            $byChannel[$channel]['total'] += $row['total'];
        }

        foreach ($byChannel as &$c) {
            $c['deliveryRate'] = $c['total'] > 0
                ? round($c['sent'] / $c['total'] * 100, 1)
                : 0.0;
        }
        unset($c);

        $campaigns = $this->campaigns->all();
        $campaignStats = [
            'total'     => count($campaigns),
            'byStatus'  => [],
        ];
        foreach ($campaigns as $campaign) {
            $s = $campaign['status'];
            $campaignStats['byStatus'][$s] = ($campaignStats['byStatus'][$s] ?? 0) + 1;
        }

        // Rendimiento de las campañas con envíos, las más recientes primero.
        $performance = [];
        foreach ($campaigns as $campaign) {
            $totalRecipients = (int) $campaign['total_recipients'];
            if ($totalRecipients === 0) {
                continue;
            }
            $sent = (int) $campaign['sent_count'];
            $performance[] = [
                'id'           => (int) $campaign['id'],
                'name'         => $campaign['name'],
                'channel'      => $campaign['channel'],
                'status'       => $campaign['status'],
                'total'        => $totalRecipients,
                'sent'         => $sent,
                'failed'       => (int) $campaign['failed_count'],
                'deliveryRate' => round($sent / $totalRecipients * 100, 1),
                'createdAt'    => $campaign['created_at'],
            ];
        }
        $performance = array_slice($performance, 0, 10);

        $grandSent = array_sum(array_column($byChannel, 'sent'));
        $grandFailed = array_sum(array_column($byChannel, 'failed'));
        $grandTotal = $grandSent + $grandFailed;

        return $this->jsonResponse($response, [
            'range' => ['from' => $from, 'to' => $to, 'days' => $days],
            'totals' => [
                'sent'         => $grandSent,
                'failed'       => $grandFailed,
                'total'        => $grandTotal,
                'deliveryRate' => $grandTotal > 0 ? round($grandSent / $grandTotal * 100, 1) : 0.0,
            ],
            'byChannel'          => array_values($byChannel),
            'dailySeries'        => $this->messages->dailySeries($days),
            'campaigns'          => $campaignStats,
            'campaignPerformance' => $performance,
            'topFailures'        => $this->messages->topFailures(10),
            'suppressions'       => $this->suppressions->countsByReason(),
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

    private function param(array $query, string $key): ?string
    {
        $value = trim((string) ($query[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
