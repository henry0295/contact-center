<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class SenderRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    /**
     * Crea o actualiza un remitente. `$type` es 'sender' (aparece en "De") o
     * 'reply' (recibe las respuestas vía Reply-To); solo se aplica al CREAR,
     * nunca se pisa en una fila existente, para que sincronizar estados desde
     * SES no cambie de rol a un remitente ya clasificado.
     */
    public function upsert(string $email, string $verificationStatus = 'Pending', string $type = 'sender'): void
    {
        // Nota: no se toca is_default aquí para no perder el remitente por defecto
        // cuando el listado sincroniza estados desde SES.
        $sql = 'INSERT INTO senders (email, verification_status, type)
                VALUES (:email, :status, :type)
                ON DUPLICATE KEY UPDATE verification_status = VALUES(verification_status)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':status', $verificationStatus, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function updateStatus(string $email, string $verificationStatus): void
    {
        $stmt = $this->pdo->prepare('UPDATE senders SET verification_status = :status WHERE email = :email');
        $stmt->bindValue(':status', $verificationStatus, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM senders WHERE email = :email LIMIT 1');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : $this->mapRow($row);
    }

    /** @param string|null $type si se indica, filtra por 'sender' o 'reply'. */
    public function all(?string $type = null): array
    {
        if ($type !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM senders WHERE type = :type ORDER BY is_default DESC, created_at DESC');
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->execute();

            return array_map([$this, 'mapRow'], $stmt->fetchAll());
        }

        $stmt = $this->pdo->query('SELECT * FROM senders ORDER BY is_default DESC, created_at DESC');

        return array_map([$this, 'mapRow'], $stmt->fetchAll());
    }

    /**
     * Marca un remitente como el por defecto de SU TIPO (el MAIL_FROM de los
     * envíos si es 'sender', o el Reply-To automático si es 'reply') y
     * desmarca cualquier otro del MISMO tipo. Transaccional para no dejar dos
     * por defecto del mismo tipo. El tipo se toma de la fila existente.
     */
    public function setDefault(string $email): void
    {
        $sender = $this->findByEmail($email);
        if ($sender === null) {
            throw new \InvalidArgumentException('El remitente no existe.');
        }
        $type = $sender['type'];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE senders SET is_default = 0 WHERE is_default = 1 AND type = :type');
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->execute();

            $stmt = $this->pdo->prepare('UPDATE senders SET is_default = 1 WHERE email = :email');
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->execute();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Remitente marcado como por defecto de ese tipo, si existe. */
    public function getDefault(string $type = 'sender'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM senders WHERE is_default = 1 AND type = :type LIMIT 1');
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $this->mapRow($row);
    }

    /** True si no hay ningún remitente de ese tipo marcado como por defecto. */
    public function hasDefault(string $type = 'sender'): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM senders WHERE is_default = 1 AND type = :type');
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function delete(string $email): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM senders WHERE email = :email');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** Remitentes que pertenecen a un dominio dado. */
    public function forDomain(string $domain): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM senders WHERE email LIKE :suffix');
        $stmt->bindValue(':suffix', '%@' . $domain, PDO::PARAM_STR);
        $stmt->execute();

        return array_map([$this, 'mapRow'], $stmt->fetchAll());
    }

    /** Quita la marca de remitente por defecto (p.ej. si dejó de estar verificado). */
    public function clearDefault(string $email): void
    {
        $stmt = $this->pdo->prepare('UPDATE senders SET is_default = 0 WHERE email = :email');
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Primer remitente verificado de un tipo, excluyendo uno dado. Se usa para
     * reasignar el remitente por defecto (de ese tipo) cuando se elimina el
     * que lo era.
     */
    public function firstVerified(?string $exclude = null, string $type = 'sender'): ?array
    {
        $sql = "SELECT * FROM senders WHERE verification_status = 'Success' AND type = :type";
        if ($exclude !== null) {
            $sql .= ' AND email <> :exclude';
        }
        $sql .= ' ORDER BY created_at ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        if ($exclude !== null) {
            $stmt->bindValue(':exclude', $exclude, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $this->mapRow($row);
    }

    private function mapRow(array $row): array
    {
        $row['is_default'] = (bool) ($row['is_default'] ?? false);

        return $row;
    }
}
