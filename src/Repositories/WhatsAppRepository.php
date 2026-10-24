<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

/** Plantillas, mensajes entrantes y estados de entrega de WhatsApp. */
class WhatsAppRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    /** Cuántas variables {{n}} distintas usa un texto. */
    public static function countVars(string $text): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m);

        return empty($m[1]) ? 0 : (int) max(array_map('intval', $m[1]));
    }

    // ------------------------------------------------------------ plantillas --

    public function templates(?string $status = null): array
    {
        $sql = 'SELECT * FROM whatsapp_templates';
        if ($status !== null) {
            $sql .= ' WHERE status = :status';
        }
        $sql .= ' ORDER BY (status = \'APPROVED\') DESC, updated_at DESC';
        $stmt = $this->pdo->prepare($sql);
        if ($status !== null) {
            $stmt->bindValue(':status', $status);
        }
        $stmt->execute();

        return array_map([$this, 'mapTemplate'], $stmt->fetchAll());
    }

    public function findTemplate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_templates WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $this->mapTemplate($row);
    }

    /** Crea o actualiza por (element_name, language). Devuelve el id local. */
    public function upsertTemplate(array $t): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO whatsapp_templates
                (external_id, element_name, language, category, template_type, header, body, footer,
                 buttons_json, example, var_count, status, reason)
             VALUES (:external_id, :element_name, :language, :category, :template_type, :header, :body, :footer,
                     :buttons_json, :example, :var_count, :status, :reason)
             ON DUPLICATE KEY UPDATE
                external_id = COALESCE(VALUES(external_id), external_id),
                category = VALUES(category), template_type = VALUES(template_type),
                header = VALUES(header), body = VALUES(body), footer = VALUES(footer),
                buttons_json = COALESCE(VALUES(buttons_json), buttons_json),
                example = COALESCE(VALUES(example), example),
                var_count = VALUES(var_count), status = VALUES(status), reason = VALUES(reason),
                id = LAST_INSERT_ID(id)'
        );
        foreach (['external_id', 'element_name', 'language', 'category', 'template_type', 'header', 'body', 'footer',
                  'buttons_json', 'example', 'status', 'reason'] as $k) {
            $v = $t[$k] ?? null;
            $stmt->bindValue(':' . $k, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->bindValue(':var_count', (int) ($t['var_count'] ?? self::countVars((string) ($t['body'] ?? ''))), PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function updateTemplateStatus(string $externalIdOrName, string $status, ?string $reason = null): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE whatsapp_templates SET status = :status, reason = :reason
              WHERE external_id = :k OR element_name = :k2'
        );
        $stmt->bindValue(':status', strtoupper($status));
        $stmt->bindValue(':reason', $reason, $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':k', $externalIdOrName);
        $stmt->bindValue(':k2', $externalIdOrName);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** Borra todas las versiones (idiomas) de una plantilla. Meta borra por nombre. */
    public function deleteTemplatesByName(string $elementName): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM whatsapp_templates WHERE element_name = :n');
        $stmt->execute([':n' => $elementName]);

        return $stmt->rowCount();
    }

    /**
     * Campañas programadas o en curso que usan alguna de las plantillas con ese
     * nombre: borrarla las dejaría sin poder enviarse.
     *
     * @return array<int,array{id:int,name:string,status:string}>
     */
    public function pendingCampaignsUsing(string $elementName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.name, c.status FROM campaigns c
               JOIN whatsapp_templates t ON t.id = c.wa_template_id
              WHERE t.element_name = :n AND c.status IN ('Scheduled', 'Sending', 'Draft')
              ORDER BY c.id DESC LIMIT 10"
        );
        $stmt->execute([':n' => $elementName]);

        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'status' => (string) $r['status']], $stmt->fetchAll());
    }

    /** Tras editar en Meta: contenido nuevo y vuelve a revisión. */
    public function updateTemplateContent(int $id, array $t): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE whatsapp_templates SET category = :category, header = :header, body = :body, footer = :footer,
                    buttons_json = :buttons, example = :example, var_count = :vars, status = 'PENDING', reason = NULL
              WHERE id = :id"
        );
        foreach (['category' => 'category', 'header' => 'header', 'body' => 'body', 'footer' => 'footer', 'buttons' => 'buttons_json', 'example' => 'example'] as $p => $k) {
            $v = $t[$k] ?? null;
            $stmt->bindValue(':' . $p, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->bindValue(':vars', (int) ($t['var_count'] ?? self::countVars((string) ($t['body'] ?? ''))), PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteTemplate(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM whatsapp_templates WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    private function mapTemplate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['var_count'] = (int) $row['var_count'];
        $row['buttons'] = $row['buttons_json'] ? (json_decode((string) $row['buttons_json'], true) ?: []) : [];
        unset($row['buttons_json']);

        return $row;
    }

    // ------------------------------------------------------ estados de envío --

    public function setRecipientExternalId(int $recipientId, string $externalId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE campaign_recipients SET external_id = :ext, delivery_status = 'accepted' WHERE id = :id"
        );
        $stmt->bindValue(':ext', $externalId);
        $stmt->bindValue(':id', $recipientId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function recipientByExternalId(array $ids): ?array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if (empty($ids)) {
            return null;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cr.*, c.channel, c.wa_template_id FROM campaign_recipients cr
               JOIN campaigns c ON c.id = cr.campaign_id
              WHERE cr.external_id IN ($in) LIMIT 1"
        );
        $stmt->execute($ids);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Guarda el último estado y, si llegó el wamid, lo usa como id para eventos siguientes. */
    public function setDeliveryStatus(int $recipientId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE campaign_recipients SET delivery_status = :s WHERE id = :id');
        $stmt->bindValue(':s', mb_substr($status, 0, 16));
        $stmt->bindValue(':id', $recipientId, PDO::PARAM_INT);
        $stmt->execute();
    }

    // -------------------------------------------------------------- entrantes --

    /** @return bool false si el mensaje ya estaba (reintento del proveedor). */
    public function saveInbound(array $m): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO whatsapp_inbound
                (wa_message_id, phone, profile_name, message_type, message, campaign_id, contact_id, is_optout)
             VALUES (:id, :phone, :name, :type, :message, :campaign_id, :contact_id, :optout)'
        );
        $stmt->bindValue(':id', $m['wa_message_id'] ?? null, empty($m['wa_message_id']) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':phone', $m['phone']);
        $stmt->bindValue(':name', $m['profile_name'] ?? null, empty($m['profile_name']) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':type', $m['message_type'] ?? 'text');
        $stmt->bindValue(':message', $m['message']);
        $stmt->bindValue(':campaign_id', $m['campaign_id'] ?? null, isset($m['campaign_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':contact_id', $m['contact_id'] ?? null, isset($m['contact_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':optout', !empty($m['is_optout']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Campaña de WhatsApp más reciente enviada a ese número (últimos 30 días).
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
            "SELECT cr.campaign_id, cr.contact_id FROM campaign_recipients cr
               JOIN campaigns c ON c.id = cr.campaign_id
              WHERE c.channel = 'whatsapp'
                AND REGEXP_REPLACE(cr.phone, '[^0-9]', '') LIKE :suffix
                AND cr.sent_at >= NOW() - INTERVAL 30 DAY
              ORDER BY cr.id DESC LIMIT 1"
        );
        $stmt->bindValue(':suffix', '%' . $last10);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : [
            'campaign_id' => (int) $row['campaign_id'],
            'contact_id'  => $row['contact_id'] !== null ? (int) $row['contact_id'] : null,
        ];
    }

    public function unsubscribeByPhone(string $phoneDigits): int
    {
        return (new SmsReplyRepository($this->pdo))->unsubscribeByPhone($phoneDigits);
    }

    /** @return array{replies:array,total:int} */
    public function inbound(int $page, int $perPage, string $search = ''): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE (i.phone LIKE :q OR i.message LIKE :q2 OR i.profile_name LIKE :q3)';
            $params = [':q' => "%$search%", ':q2' => "%$search%", ':q3' => "%$search%"];
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM whatsapp_inbound i $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.phone, i.profile_name, i.message_type, i.message, i.campaign_id, i.is_optout, i.received_at,
                    c.name AS campaign_name, ct.name AS contact_name
               FROM whatsapp_inbound i
               LEFT JOIN campaigns c ON c.id = i.campaign_id
               LEFT JOIN contacts ct ON ct.id = i.contact_id
               $where ORDER BY i.id DESC LIMIT :limit OFFSET :offset"
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
