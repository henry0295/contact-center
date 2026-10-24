<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class CampaignRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function create(
        string $name,
        string $channel,
        ?int $templateId,
        ?string $subject,
        string $body,
        string $status,
        ?string $scheduledAt,
        int $totalRecipients
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO campaigns (name, channel, template_id, subject, body, status, scheduled_at, total_recipients)
             VALUES (:name, :channel, :template_id, :subject, :body, :status, :scheduled_at, :total)'
        );
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->bindValue(':template_id', $templateId, $templateId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':subject', $subject, $subject === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':scheduled_at', $scheduledAt, $scheduledAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':total', $totalRecipients, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Listado paginado, con filtros opcionales por estado y canal.
     * @return array{items:array, total:int}
     */
    public function paginate(
        int $limit,
        int $offset,
        ?string $status = null,
        ?string $channel = null,
        ?string $origin = null,
        array $extra = []
    ): array {
        $conditions = [];
        $params = [];

        // Filtros adicionales: search (nombre o etiquetas), from/to (Y-m-d), createdBy.
        if (!empty($extra['search'])) {
            $conditions[] = '(name LIKE :q1 OR tags LIKE :q2)';
            $params[':q1'] = '%' . $extra['search'] . '%';
            $params[':q2'] = '%' . $extra['search'] . '%';
        }
        if (!empty($extra['from'])) {
            $conditions[] = 'created_at >= :from';
            $params[':from'] = $extra['from'] . ' 00:00:00';
        }
        if (!empty($extra['to'])) {
            $conditions[] = 'created_at <= :to';
            $params[':to'] = $extra['to'] . ' 23:59:59';
        }
        if (!empty($extra['createdBy'])) {
            $conditions[] = 'created_by = :cb';
            $params[':cb'] = $extra['createdBy'];
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }
        if ($channel !== null && $channel !== '') {
            $conditions[] = 'channel = :channel';
            $params[':channel'] = $channel;
        }
        if ($origin !== null && $origin !== '') {
            $conditions[] = 'origin = :origin';
            $params[':origin'] = $origin;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM campaigns {$where}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM campaigns {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Todas las campañas. Solo para procesos internos, no para la API. */
    /**
     * Alta con todos los campos del compositor (remitente, caducidad,
     * etiquetas, segmentos, coste y origen).
     *
     * @param array<string,mixed> $data
     */
    public function createFull(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO campaigns
                (name, channel, template_id, subject, body, status, scheduled_at, total_recipients,
                 sender_id, expires_at, tags, segments, cost_estimate, origin, voice_gender, reply_to, sms_type,
                 wa_template_id, wa_params_json, created_by)
             VALUES
                (:name, :channel, :template_id, :subject, :body, :status, :scheduled_at, :total,
                 :sender_id, :expires_at, :tags, :segments, :cost, :origin, :voice_gender, :reply_to, :sms_type,
                 :wa_template_id, :wa_params_json, :created_by)'
        );
        $createdBy = $data['createdBy'] ?? \App\Support\CurrentUser::email();
        $stmt->bindValue(':created_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        $nullable = static fn ($v): int => $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR;

        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':channel', $data['channel'], PDO::PARAM_STR);
        $stmt->bindValue(':template_id', $data['templateId'] ?? null, ($data['templateId'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':subject', $data['subject'] ?? null, $nullable($data['subject'] ?? null));
        $stmt->bindValue(':body', $data['body'], PDO::PARAM_STR);
        $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
        $stmt->bindValue(':scheduled_at', $data['scheduledAt'] ?? null, $nullable($data['scheduledAt'] ?? null));
        $stmt->bindValue(':total', (int) ($data['totalRecipients'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':sender_id', $data['senderId'] ?? null, $nullable($data['senderId'] ?? null));
        $stmt->bindValue(':expires_at', $data['expiresAt'] ?? null, $nullable($data['expiresAt'] ?? null));
        $stmt->bindValue(':tags', $data['tags'] ?? null, $nullable($data['tags'] ?? null));
        $stmt->bindValue(':segments', (int) ($data['segments'] ?? 1), PDO::PARAM_INT);
        $stmt->bindValue(':cost', $data['costEstimate'] ?? null, $nullable($data['costEstimate'] ?? null));
        $stmt->bindValue(':origin', $data['origin'] ?? 'campaign', PDO::PARAM_STR);
        $stmt->bindValue(':voice_gender', $data['voiceGender'] ?? null, $nullable($data['voiceGender'] ?? null));
        $stmt->bindValue(':reply_to', $data['replyTo'] ?? null, $nullable($data['replyTo'] ?? null));
        $stmt->bindValue(':sms_type', $data['smsType'] ?? null, $nullable($data['smsType'] ?? null));
        $stmt->bindValue(':wa_template_id', $data['waTemplateId'] ?? null, isset($data['waTemplateId']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':wa_params_json', $data['waParamsJson'] ?? null, $nullable($data['waParamsJson'] ?? null));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM campaigns ORDER BY created_at DESC')->fetchAll();
    }

    /**
     * Últimos textos enviados por canal, para el panel "mensajes anteriores"
     * del compositor. Se agrupan por cuerpo para no repetir el mismo texto.
     */
    public function recentBodies(string $channel, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(id) AS id, MAX(name) AS name, subject, body, MAX(created_at) AS created_at
               FROM campaigns
              WHERE channel = :channel AND body IS NOT NULL AND body <> \'\'
              GROUP BY body, subject
              ORDER BY MAX(created_at) DESC
              LIMIT :limit'
        );
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM campaigns WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE campaigns SET status = :status WHERE id = :id');
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Recalcula sent_count/failed_count desde campaign_recipients (robusto ante reintentos). */
    public function refreshCounts(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE campaigns c SET
                sent_count   = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = c.id AND status = 'Sent'),
                failed_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = c.id AND status = 'Failed')
             WHERE c.id = :id"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Cancela una campaña programada: vuelve a Draft y borra la fecha, para que
     * el dispatcher deje de considerarla vencida.
     * Solo se permite si todavía no empezó a enviarse.
     */
    public function cancelSchedule(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE campaigns
                SET status = 'Draft', scheduled_at = NULL
              WHERE id = :id AND status = 'Scheduled'"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM campaigns WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** Campañas programadas cuya hora ya llegó. */
    public function dueScheduled(): array
    {
        return $this->pdo
            ->query("SELECT * FROM campaigns WHERE status = 'Scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW() ORDER BY scheduled_at ASC")
            ->fetchAll();
    }
}
