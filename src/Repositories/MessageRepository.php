<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class MessageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function log(
        string $channel,
        string $recipient,
        ?string $subject,
        string $status,
        ?string $error = null,
        ?int $campaignId = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages (channel, recipient, subject, status, error, campaign_id)
             VALUES (:channel, :recipient, :subject, :status, :error, :campaign_id)'
        );
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->bindValue(':recipient', $recipient, PDO::PARAM_STR);
        $stmt->bindValue(':subject', $subject, $subject === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':error', $error, $error === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':campaign_id', $campaignId, $campaignId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    public function countByChannel(string $channel, string $status = 'Sent'): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE channel = :channel AND status = :status');
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Historial paginado con filtros. Es la base del módulo de Reportes.
     * @return array{items:array, total:int}
     */
    public function paginate(
        int $limit,
        int $offset,
        ?string $channel = null,
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
        ?string $search = null
    ): array {
        $conditions = [];
        $params = [];

        if ($channel !== null && $channel !== '') {
            $conditions[] = 'channel = :channel';
            $params[':channel'] = $channel;
        }
        if ($status !== null && $status !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }
        if ($from !== null && $from !== '') {
            $conditions[] = 'created_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $conditions[] = 'created_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        if ($search !== null && $search !== '') {
            // Marcadores distintos: con prepares nativos no se puede repetir
            // el mismo nombre en una consulta.
            $conditions[] = '(recipient LIKE :s1 OR subject LIKE :s2)';
            $like = '%' . $search . '%';
            $params[':s1'] = $like;
            $params[':s2'] = $like;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM messages {$where}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM messages {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Totales por canal y estado, para los indicadores de Reportes. */
    public function totalsByChannelAndStatus(?string $from = null, ?string $to = null): array
    {
        $conditions = [];
        $params = [];
        if ($from !== null && $from !== '') {
            $conditions[] = 'created_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $conditions[] = 'created_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $this->pdo->prepare(
            "SELECT channel, status, COUNT(*) AS total FROM messages {$where} GROUP BY channel, status"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();

        return array_map(static function (array $r): array {
            $r['total'] = (int) $r['total'];

            return $r;
        }, $stmt->fetchAll());
    }

    /** Serie diaria de los últimos N días, para el gráfico de evolución. */
    public function dailySeries(int $days = 30): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATE(created_at) AS dia, channel, status, COUNT(*) AS total
             FROM messages
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY dia, channel, status
             ORDER BY dia ASC"
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $r): array {
            $r['total'] = (int) $r['total'];

            return $r;
        }, $stmt->fetchAll());
    }

    /** Destinatarios con más fallos, para detectar direcciones problemáticas. */
    public function topFailures(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT recipient, channel, COUNT(*) AS fallos, MAX(created_at) AS ultimo
             FROM messages WHERE status = 'Failed'
             GROUP BY recipient, channel
             ORDER BY fallos DESC, ultimo DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $r): array {
            $r['fallos'] = (int) $r['fallos'];

            return $r;
        }, $stmt->fetchAll());
    }
}
