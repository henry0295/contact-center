<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

/**
 * Métricas de Reportes sobre un rango [desde, hasta) de fechas y hora
 * ('Y-m-d H:i:s'): envíos por canal, gasto, motivos de fallo, embudo de
 * WhatsApp, resultado de llamadas y estadísticas por campaña.
 */
class ReportRepository
{
    /** Motivos fijos que registra CampaignService para las llamadas. */
    public const CALL_BUSY = 'Línea ocupada.';
    public const CALL_NO_ANSWER = 'No contestó la llamada.';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    /**
     * Enviados y fallidos por canal (tabla messages; una llamada registra
     * "Dialing" y luego su resultado, así que solo cuentan Sent/Failed).
     *
     * @return array<string,array{channel:string,sent:int,failed:int,total:int,deliveryRate:float}>
     */
    public function byChannel(string $since, string $until): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT channel, SUM(status = 'Sent') AS sent, SUM(status = 'Failed') AS failed
               FROM messages
              WHERE created_at >= :since AND created_at < :until AND status IN ('Sent', 'Failed')
              GROUP BY channel"
        );
        $stmt->execute([':since' => $since, ':until' => $until]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $sent = (int) $r['sent'];
            $failed = (int) $r['failed'];
            $out[(string) $r['channel']] = [
                'channel'      => (string) $r['channel'],
                'sent'         => $sent,
                'failed'       => $failed,
                'total'        => $sent + $failed,
                'deliveryRate' => self::rate($sent, $sent + $failed),
            ];
        }

        return $out;
    }

    /** Serie diaria (Sent/Failed por canal) del rango. */
    public function dailySeries(string $since, string $until): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATE(created_at) AS dia, channel, status, COUNT(*) AS total
               FROM messages
              WHERE created_at >= :since AND created_at < :until AND status IN ('Sent', 'Failed')
              GROUP BY dia, channel, status
              ORDER BY dia ASC"
        );
        $stmt->execute([':since' => $since, ':until' => $until]);

        return array_map(static fn (array $r) => ['dia' => (string) $r['dia'], 'channel' => (string) $r['channel'], 'status' => (string) $r['status'], 'total' => (int) $r['total']], $stmt->fetchAll());
    }

    /**
     * Gasto neto (débitos − reembolsos) y reembolsos del rango, por canal.
     * Las claves whatsapp_* se agrupan en "whatsapp".
     *
     * @return array{total:float,refunded:float,byChannel:array<string,float>}
     */
    public function spend(string $since, string $until): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT channel,
                    -SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS debited,
                    SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END) AS refunded
               FROM credit_transactions
              WHERE type IN ('debit', 'refund') AND created_at >= :since AND created_at < :until
              GROUP BY channel"
        );
        $stmt->execute([':since' => $since, ':until' => $until]);
        $by = [];
        $refunded = 0.0;
        foreach ($stmt->fetchAll() as $r) {
            $ch = self::channelOf((string) $r['channel']);
            $by[$ch] = round(($by[$ch] ?? 0) + (float) $r['debited'] - (float) $r['refunded'], 2);
            $refunded += (float) $r['refunded'];
        }

        return ['total' => round(array_sum($by), 2), 'refunded' => round($refunded, 2), 'byChannel' => $by];
    }

    /**
     * Motivos de fallo más frecuentes en campañas del rango (o de una campaña).
     *
     * @return array<int,array{reason:string,count:int,channel:?string}>
     */
    public function failureReasons(?string $since, ?string $until, ?int $campaignId = null, int $limit = 10): array
    {
        $where = "cr.status = 'Failed' AND cr.error IS NOT NULL AND cr.error <> ''";
        $params = [];
        if ($campaignId !== null) {
            $where .= ' AND cr.campaign_id = :cid';
            $params[':cid'] = $campaignId;
        } else {
            $where .= ' AND c.created_at >= :since AND c.created_at < :until';
            $params[':since'] = $since;
            $params[':until'] = $until;
        }
        $stmt = $this->pdo->prepare(
            "SELECT LEFT(cr.error, 160) AS reason, c.channel, COUNT(*) AS n
               FROM campaign_recipients cr
               JOIN campaigns c ON c.id = cr.campaign_id
              WHERE $where
              GROUP BY reason, c.channel
              ORDER BY n DESC
              LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute($params);

        return array_map(static fn (array $r) => [
            'reason'  => (string) $r['reason'],
            'count'   => (int) $r['n'],
            'channel' => (string) $r['channel'],
        ], $stmt->fetchAll());
    }

    /**
     * Embudo de WhatsApp (enviados → entregados → leídos) del rango.
     *
     * @return array{sent:int,delivered:int,read:int,failed:int,deliveredRate:float,readRate:float}
     */
    public function whatsappFunnel(string $since, string $until): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT " . self::FUNNEL_COLS . "
               FROM campaign_recipients cr JOIN campaigns c ON c.id = cr.campaign_id
              WHERE c.channel = 'whatsapp' AND c.created_at >= :since AND c.created_at < :until"
        );
        $stmt->execute([':since' => $since, ':until' => $until]);

        return self::funnel($stmt->fetch() ?: []);
    }

    /**
     * Resultado de las llamadas del rango.
     *
     * @return array{answered:int,busy:int,noAnswer:int,otherFailed:int,dialing:int,answerRate:float}
     */
    public function callOutcomes(string $since, string $until): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT " . self::CALL_COLS . "
               FROM campaign_recipients cr JOIN campaigns c ON c.id = cr.campaign_id
              WHERE c.channel = 'call' AND c.created_at >= :since AND c.created_at < :until"
        );
        $stmt->execute([':since' => $since, ':until' => $until, ':busy' => self::CALL_BUSY, ':noans' => self::CALL_NO_ANSWER]);

        return self::calls($stmt->fetch() ?: []);
    }

    /** Respuestas y bajas recibidas en el rango (SMS doble vía + WhatsApp). */
    public function repliesSummary(string $since, string $until): array
    {
        $out = ['replies' => 0, 'optOuts' => 0];
        foreach (['sms_replies', 'whatsapp_inbound'] as $table) {
            $stmt = $this->pdo->prepare(
                "SELECT SUM(is_optout = 0) AS r, SUM(is_optout = 1) AS o FROM $table
                  WHERE received_at >= :since AND received_at < :until"
            );
            $stmt->execute([':since' => $since, ':until' => $until]);
            $row = $stmt->fetch() ?: [];
            $out['replies'] += (int) ($row['r'] ?? 0);
            $out['optOuts'] += (int) ($row['o'] ?? 0);
        }

        return $out;
    }

    /**
     * Estadísticas por campaña: gasto, reembolsos, respuestas, bajas, embudo
     * de WhatsApp y resultado de llamadas.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    public function campaignStats(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (empty($ids)) {
            return [];
        }
        $in = implode(',', $ids); // enteros ya validados
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['cost' => 0.0, 'refunded' => 0.0, 'replies' => 0, 'optOuts' => 0, 'funnel' => null, 'calls' => null];
        }

        foreach ($this->pdo->query(
            "SELECT campaign_id,
                    -SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS debited,
                    SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END) AS refunded
               FROM credit_transactions
              WHERE campaign_id IN ($in) AND type IN ('debit', 'refund')
              GROUP BY campaign_id"
        )->fetchAll() as $r) {
            $id = (int) $r['campaign_id'];
            $out[$id]['cost'] = round((float) $r['debited'] - (float) $r['refunded'], 2);
            $out[$id]['refunded'] = round((float) $r['refunded'], 2);
        }

        foreach (['sms_replies', 'whatsapp_inbound'] as $table) {
            foreach ($this->pdo->query(
                "SELECT campaign_id, SUM(is_optout = 0) AS r, SUM(is_optout = 1) AS o
                   FROM $table WHERE campaign_id IN ($in) GROUP BY campaign_id"
            )->fetchAll() as $r) {
                $id = (int) $r['campaign_id'];
                $out[$id]['replies'] += (int) $r['r'];
                $out[$id]['optOuts'] += (int) $r['o'];
            }
        }

        foreach ($this->pdo->query(
            "SELECT cr.campaign_id, " . self::FUNNEL_COLS . "
               FROM campaign_recipients cr JOIN campaigns c ON c.id = cr.campaign_id
              WHERE c.channel = 'whatsapp' AND cr.campaign_id IN ($in)
              GROUP BY cr.campaign_id"
        )->fetchAll() as $r) {
            $out[(int) $r['campaign_id']]['funnel'] = self::funnel($r);
        }

        $stmt = $this->pdo->prepare(
            "SELECT cr.campaign_id, " . self::CALL_COLS . "
               FROM campaign_recipients cr JOIN campaigns c ON c.id = cr.campaign_id
              WHERE c.channel = 'call' AND cr.campaign_id IN ($in)
              GROUP BY cr.campaign_id"
        );
        $stmt->execute([':busy' => self::CALL_BUSY, ':noans' => self::CALL_NO_ANSWER]);
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['campaign_id']]['calls'] = self::calls($r);
        }

        return $out;
    }

    /**
     * Campañas creadas en el rango (para el resumen y la exportación).
     *
     * @return array<int,array<string,mixed>>
     */
    public function campaignsInRange(string $since, string $until, int $limit = 500): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM campaigns WHERE created_at >= :since AND created_at < :until
              ORDER BY created_at DESC LIMIT ' . max(1, min(5000, $limit))
        );
        $stmt->execute([':since' => $since, ':until' => $until]);

        return $stmt->fetchAll();
    }

    /** Destinatarios de una campaña con el nombre del contacto (exportación). */
    public function recipientsForExport(int $campaignId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.email, cr.phone, ct.name AS contact_name, cr.status, cr.delivery_status, cr.error, cr.sent_at
               FROM campaign_recipients cr
               LEFT JOIN contacts ct ON ct.id = cr.contact_id
              WHERE cr.campaign_id = :id ORDER BY cr.id ASC'
        );
        $stmt->execute([':id' => $campaignId]);

        return $stmt->fetchAll();
    }

    /** Usuarios que han creado campañas (para el filtro). */
    public function creators(): array
    {
        return array_map('strval', $this->pdo->query(
            "SELECT DISTINCT created_by FROM campaigns WHERE created_by IS NOT NULL AND created_by <> '' ORDER BY created_by"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------ interno --

    private const FUNNEL_COLS = "SUM(cr.status = 'Sent') AS sent,
                    SUM(cr.status = 'Sent' AND cr.delivery_status IN ('delivered', 'read')) AS delivered,
                    SUM(cr.status = 'Sent' AND cr.delivery_status = 'read') AS readc,
                    SUM(cr.status = 'Failed') AS failed";

    private const CALL_COLS = "SUM(cr.status = 'Sent') AS answered,
                    SUM(cr.status = 'Failed' AND cr.error = :busy) AS busy,
                    SUM(cr.status = 'Failed' AND cr.error = :noans) AS noanswer,
                    SUM(cr.status = 'Failed') AS failed,
                    SUM(cr.status = 'Dialing') AS dialing";

    private static function funnel(array $r): array
    {
        $sent = (int) ($r['sent'] ?? 0);
        $delivered = (int) ($r['delivered'] ?? 0);
        $read = (int) ($r['readc'] ?? 0);

        return [
            'sent'          => $sent,
            'delivered'     => $delivered,
            'read'          => $read,
            'failed'        => (int) ($r['failed'] ?? 0),
            'deliveredRate' => self::rate($delivered, $sent),
            'readRate'      => self::rate($read, $sent),
        ];
    }

    private static function calls(array $r): array
    {
        $answered = (int) ($r['answered'] ?? 0);
        $busy = (int) ($r['busy'] ?? 0);
        $noAnswer = (int) ($r['noanswer'] ?? 0);
        $failed = (int) ($r['failed'] ?? 0);

        return [
            'answered'    => $answered,
            'busy'        => $busy,
            'noAnswer'    => $noAnswer,
            'otherFailed' => max(0, $failed - $busy - $noAnswer),
            'dialing'     => (int) ($r['dialing'] ?? 0),
            'answerRate'  => self::rate($answered, $answered + $failed),
        ];
    }

    public static function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
    }

    public static function channelOf(string $priceKey): string
    {
        return str_starts_with($priceKey, 'whatsapp') ? 'whatsapp' : $priceKey;
    }
}
