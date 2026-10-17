<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class TemplateRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    public function all(?string $channel = null): array
    {
        if ($channel !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM templates WHERE channel = :channel ORDER BY created_at DESC');
            $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->fetchAll();
        }

        return $this->pdo->query('SELECT * FROM templates ORDER BY created_at DESC')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM templates WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(
        string $name,
        string $channel,
        ?string $subject,
        string $body,
        ?string $designJson = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO templates (name, channel, subject, body, design_json)
             VALUES (:name, :channel, :subject, :body, :design)'
        );
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
        $stmt->bindValue(':subject', $subject, $subject === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        $stmt->bindValue(':design', $designJson, $designJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        ?string $subject,
        string $body,
        ?string $designJson = null
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE templates SET name = :name, subject = :subject, body = :body, design_json = :design
             WHERE id = :id'
        );
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':subject', $subject, $subject === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':body', $body, PDO::PARAM_STR);
        $stmt->bindValue(':design', $designJson, $designJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() >= 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM templates WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
