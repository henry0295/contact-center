<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class ContactListRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    /** Listas con el número de contactos miembro. */
    public function all(): array
    {
        $sql = 'SELECT l.*, COUNT(m.contact_id) AS member_count
                FROM contact_lists l
                LEFT JOIN contact_list_members m ON m.list_id = l.id
                GROUP BY l.id
                ORDER BY l.created_at DESC';

        return array_map(static function (array $row): array {
            $row['member_count'] = (int) $row['member_count'];

            return $row;
        }, $this->pdo->query($sql)->fetchAll());
    }

    public function create(string $name, ?string $description): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO contact_lists (name, description) VALUES (:name, :description)');
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, $description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contact_lists WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM contact_lists WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function addMember(int $listId, int $contactId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO contact_list_members (list_id, contact_id) VALUES (:list, :contact)'
        );
        $stmt->bindValue(':list', $listId, PDO::PARAM_INT);
        $stmt->bindValue(':contact', $contactId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function removeMember(int $listId, int $contactId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM contact_list_members WHERE list_id = :list AND contact_id = :contact'
        );
        $stmt->bindValue(':list', $listId, PDO::PARAM_INT);
        $stmt->bindValue(':contact', $contactId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /** Contactos miembros de una lista. */
    public function members(int $listId): array
    {
        $sql = 'SELECT c.* FROM contacts c
                INNER JOIN contact_list_members m ON m.contact_id = c.id
                WHERE m.list_id = :list';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':list', $listId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Miembros a los que SÍ se puede enviar: excluye a quienes se dieron de
     * baja. Se separa de members() para que la pantalla de la lista siga
     * mostrando a todos, pero el recuento de un envío no cuente bajas.
     */
    public function activeMembers(int $listId): array
    {
        $sql = 'SELECT c.* FROM contacts c
                INNER JOIN contact_list_members m ON m.contact_id = c.id
                WHERE m.list_id = :list AND c.unsubscribed_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':list', $listId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Emails no vacíos de los miembros de una lista. */
    public function memberEmails(int $listId): array
    {
        return array_values(array_filter(array_map(
            static fn (array $c): ?string => $c['email'] ?: null,
            $this->members($listId)
        )));
    }

    /** Teléfonos no vacíos de los miembros de una lista. */
    public function memberPhones(int $listId): array
    {
        return array_values(array_filter(array_map(
            static fn (array $c): ?string => $c['phone'] ?: null,
            $this->members($listId)
        )));
    }
}
