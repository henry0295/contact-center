<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class CampaignRecipientRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    /**
     * Inserta destinatarios de una campaña.
     *
     * `vars` son variables propias de ESTE envío (p.ej. "valor" de una cuota),
     * distintas de los datos del contacto: un recordatorio de pago no es un
     * dato del contacto porque cambia en cada envío, así que no se guarda en
     * `contacts` sino aquí, ligado a esta fila.
     *
     * @param array<int,array{email?:?string,phone?:?string,contact_id?:?int,vars?:?array<string,string>}> $recipients
     */
    public function addMany(int $campaignId, array $recipients): void
    {
        if (empty($recipients)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO campaign_recipients (campaign_id, contact_id, email, phone, vars_json)
             VALUES (:campaign_id, :contact_id, :email, :phone, :vars)'
        );

        foreach ($recipients as $r) {
            $contactId = $r['contact_id'] ?? null;
            $email = $r['email'] ?? null;
            $phone = $r['phone'] ?? null;
            $vars = !empty($r['vars']) ? json_encode($r['vars'], JSON_UNESCAPED_UNICODE) : null;

            $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
            $stmt->bindValue(':contact_id', $contactId, $contactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':vars', $vars, $vars === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->execute();
        }
    }

    public function pending(int $campaignId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM campaign_recipients WHERE campaign_id = :id AND status = 'Pending'"
        );
        $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function forCampaign(int $campaignId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM campaign_recipients WHERE campaign_id = :id ORDER BY id ASC');
        $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markSent(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE campaign_recipients SET status = 'Sent', error = NULL, sent_at = NOW() WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare("UPDATE campaign_recipients SET status = 'Failed', error = :error WHERE id = :id");
        $stmt->bindValue(':error', $error, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Marca que la llamada ya se originó (Asterisk la está marcando), pero su
     * resultado todavía no se conoce. No es "Sent" ni "Failed" todavía: hace
     * falta otra consulta más tarde (ver dueForCallStatus()) para saber si
     * contestó, estaba ocupado o no respondió.
     */
    public function markDialing(int $id, string $callId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE campaign_recipients SET status = 'Dialing', call_id = :call_id, dialed_at = NOW() WHERE id = :id"
        );
        $stmt->bindValue(':call_id', $callId, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Llamadas originadas (status Dialing) a las que ya les ha pasado al
     * menos `$minSecondsAgo` segundos: se les da tiempo a que alguien
     * conteste antes de ir a preguntar su estado a la API de voz.
     */
    public function dueForCallStatus(int $minSecondsAgo = 20, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM campaign_recipients
              WHERE status = 'Dialing' AND call_id IS NOT NULL
                AND dialed_at <= DATE_SUB(NOW(), INTERVAL :secs SECOND)
              ORDER BY dialed_at ASC
              LIMIT {$limit}"
        );
        $stmt->bindValue(':secs', $minSecondsAgo, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Cuántos destinatarios de una campaña siguen en 'Dialing'. */
    public function countDialing(int $campaignId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = :id AND status = 'Dialing'"
        );
        $stmt->bindValue(':id', $campaignId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
