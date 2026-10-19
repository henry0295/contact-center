<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

/** Respuestas recibidas de SMS de doble vía. */
class SmsReplyRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    /** ¿Ya llegó esta misma respuesta hace poco? (el proveedor puede reintentar) */
    public function isDuplicate(string $phone, string $message, int $minutes = 5): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sms_replies
              WHERE phone = :phone AND message = :message
                AND received_at >= NOW() - INTERVAL :minutes MINUTE'
        );
        $stmt->bindValue(':phone', $phone);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Campaña SMS más reciente enviada a ese número (últimos 30 días),
     * priorizando las de doble vía. Se compara por los últimos 10 dígitos
     * porque los números se guardan con formatos distintos (+57, 57, sin prefijo).
     *
     * @return array{campaign_id:int, contact_id:?int}|null
     */
    public function matchRecipient(string $phoneDigits): ?array
    {
        $last10 = substr($phoneDigits, -10);
        if (strlen($last10) < 7) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT cr.campaign_id, cr.contact_id
               FROM campaign_recipients cr
               JOIN campaigns c ON c.id = cr.campaign_id
              WHERE c.channel = 'sms'
                AND REGEXP_REPLACE(cr.phone, '[^0-9]', '') LIKE :suffix
                AND cr.sent_at >= NOW() - INTERVAL 30 DAY
              ORDER BY (c.sms_type = '2via') DESC, cr.id DESC
              LIMIT 1"
        );
        $stmt->bindValue(':suffix', '%' . $last10);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'campaign_id' => (int) $row['campaign_id'],
            'contact_id'  => $row['contact_id'] !== null ? (int) $row['contact_id'] : null,
        ];
    }

    public function create(string $phone, string $message, ?int $campaignId, ?int $contactId, bool $optOut): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sms_replies (phone, message, campaign_id, contact_id, is_optout)
             VALUES (:phone, :message, :campaign_id, :contact_id, :optout)'
        );
        $stmt->bindValue(':phone', $phone);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':campaign_id', $campaignId, $campaignId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':contact_id', $contactId, $contactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':optout', $optOut ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** Da de baja (no más envíos) a los contactos con ese número. */
    public function unsubscribeByPhone(string $phoneDigits): int
    {
        $last10 = substr($phoneDigits, -10);
        if (strlen($last10) < 7) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE contacts SET unsubscribed_at = NOW()
              WHERE unsubscribed_at IS NULL
                AND REGEXP_REPLACE(phone, '[^0-9]', '') LIKE :suffix"
        );
        $stmt->bindValue(':suffix', '%' . $last10);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @return array{replies:array, total:int}
     */
    public function paginate(int $page, int $perPage, ?int $campaignId = null, string $search = ''): array
    {
        $where = [];
        $params = [];
        if ($campaignId !== null) {
            $where[] = 'r.campaign_id = :campaign_id';
            $params[':campaign_id'] = $campaignId;
        }
        if ($search !== '') {
            $where[] = '(r.phone LIKE :q OR r.message LIKE :q2)';
            $params[':q'] = '%' . $search . '%';
            $params[':q2'] = '%' . $search . '%';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM sms_replies r {$sqlWhere}");
        foreach ($params as $k => $v) {
            $count->bindValue($k, $v);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.phone, r.message, r.campaign_id, r.is_optout, r.received_at,
                    c.name AS campaign_name, ct.name AS contact_name
               FROM sms_replies r
               LEFT JOIN campaigns c ON c.id = r.campaign_id
               LEFT JOIN contacts ct ON ct.id = r.contact_id
               {$sqlWhere}
              ORDER BY r.id DESC
              LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['campaign_id'] = $r['campaign_id'] !== null ? (int) $r['campaign_id'] : null;
            $r['is_optout'] = (bool) $r['is_optout'];

            return $r;
        }, $stmt->fetchAll());

        return ['replies' => $rows, 'total' => $total];
    }
}
