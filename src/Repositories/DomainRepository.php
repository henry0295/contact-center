<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class DomainRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function upsert(
        string $domain,
        ?string $verificationToken,
        ?array $dkimTokens,
        string $verificationStatus = 'Pending',
        ?string $dkimStatus = null,
        bool $dkimEnabled = false
    ): void {
        $sql = 'INSERT INTO domains
                    (domain, verification_token, verification_status, dkim_enabled, dkim_status, dkim_tokens)
                VALUES (:domain, :token, :status, :dkim_enabled, :dkim_status, :dkim_tokens)
                ON DUPLICATE KEY UPDATE
                    verification_token = VALUES(verification_token),
                    verification_status = VALUES(verification_status),
                    dkim_enabled = VALUES(dkim_enabled),
                    dkim_status = VALUES(dkim_status),
                    dkim_tokens = VALUES(dkim_tokens)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->bindValue(':token', $verificationToken, $verificationToken === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':status', $verificationStatus, PDO::PARAM_STR);
        $stmt->bindValue(':dkim_enabled', (int) $dkimEnabled, PDO::PARAM_INT);
        $stmt->bindValue(':dkim_status', $dkimStatus, $dkimStatus === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':dkim_tokens', json_encode($dkimTokens ?? []), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function updateStatus(
        string $domain,
        string $verificationStatus,
        ?string $dkimStatus = null,
        ?bool $dkimEnabled = null
    ): void {
        $sets = ['verification_status = :status'];
        $bind = [[':status', $verificationStatus, PDO::PARAM_STR]];

        if ($dkimStatus !== null) {
            $sets[] = 'dkim_status = :dkim_status';
            $bind[] = [':dkim_status', $dkimStatus, PDO::PARAM_STR];
        }

        if ($dkimEnabled !== null) {
            $sets[] = 'dkim_enabled = :dkim_enabled';
            $bind[] = [':dkim_enabled', (int) $dkimEnabled, PDO::PARAM_INT];
        }

        $sql = 'UPDATE domains SET ' . implode(', ', $sets) . ' WHERE domain = :domain';

        $stmt = $this->pdo->prepare($sql);
        foreach ($bind as [$param, $value, $type]) {
            $stmt->bindValue($param, $value, $type);
        }
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function findByDomain(string $domain): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM domains WHERE domain = :domain LIMIT 1');
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $this->mapRow($row);
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM domains ORDER BY created_at DESC');

        return array_map([$this, 'mapRow'], $stmt->fetchAll());
    }

    public function delete(string $domain): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM domains WHERE domain = :domain');
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** Guarda (o quita, con null) el dominio MAIL FROM y su estado. */
    public function setMailFrom(string $domain, ?string $mailFrom, ?string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE domains SET mail_from_domain = :mf, mail_from_status = :st WHERE domain = :domain');
        $stmt->bindValue(':mf', $mailFrom, $mailFrom === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':st', $status, $status === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        $stmt->execute();
    }

    /** Remitentes guardados que pertenecen a este dominio (para avisar al borrar). */
    public function countSenders(string $domain): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM senders WHERE email LIKE :suffix');
        $stmt->bindValue(':suffix', '%@' . $domain, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function mapRow(array $row): array
    {
        $tokens = json_decode($row['dkim_tokens'] ?? '[]', true);
        $row['dkim_tokens'] = is_array($tokens) ? $tokens : [];
        $row['dkim_enabled'] = (bool) $row['dkim_enabled'];

        return $row;
    }
}
