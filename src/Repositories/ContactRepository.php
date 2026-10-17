<?php

namespace App\Repositories;

use App\Database\Connection;
use PDO;

class ContactRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    /**
     * Listado paginado y con búsqueda opcional.
     * @return array{items:array, total:int}
     */
    public function paginate(int $limit, int $offset, ?string $search = null): array
    {
        $where = '';
        $params = [];

        if ($search !== null && $search !== '') {
            // Un marcador con nombre NO se puede repetir con prepares nativos
            // (EMULATE_PREPARES=false): hay que usar uno distinto por columna.
            $where = 'WHERE name LIKE :s1 OR email LIKE :s2 OR phone LIKE :s3';
            $like = '%' . $search . '%';
            $params[':s1'] = $like;
            $params[':s2'] = $like;
            $params[':s3'] = $like;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM contacts {$where}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT * FROM contacts {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Todos los contactos. Solo para procesos internos, no para la API. */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM contacts ORDER BY created_at DESC')->fetchAll();
    }

    public function create(?string $name, ?string $email, ?string $phone): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO contacts (name, email, phone) VALUES (:name, :email, :phone)');
        $stmt->bindValue(':name', $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM contacts WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contacts WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByUnsubscribeToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contacts WHERE unsubscribe_token = :t LIMIT 1');
        $stmt->bindValue(':t', $token, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Devuelve el token de baja del contacto, creándolo la primera vez.
     * Se genera aquí y no en la importación para que los contactos antiguos
     * también obtengan uno en cuanto se les envía algo.
     */
    public function ensureUnsubscribeToken(int $id): ?string
    {
        $contact = $this->find($id);
        if ($contact === null) {
            return null;
        }
        if (!empty($contact['unsubscribe_token'])) {
            return (string) $contact['unsubscribe_token'];
        }

        // Reintentos por si el token chocara con el índice único.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(16));
            try {
                $stmt = $this->pdo->prepare('UPDATE contacts SET unsubscribe_token = :t WHERE id = :id');
                $stmt->bindValue(':t', $token, PDO::PARAM_STR);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();

                return $token;
            } catch (\PDOException $e) {
                if ($attempt === 4) {
                    return null;
                }
            }
        }

        return null;
    }

    /** Marca la baja. Devuelve el contacto si el token existe. */
    public function unsubscribeByToken(string $token): ?array
    {
        $contact = $this->findByUnsubscribeToken($token);
        if ($contact === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE contacts SET unsubscribed_at = NOW() WHERE id = :id AND unsubscribed_at IS NULL'
        );
        $stmt->bindValue(':id', (int) $contact['id'], PDO::PARAM_INT);
        $stmt->execute();

        return $contact;
    }

    /**
     * Reutiliza el contacto si ya existe (por email o teléfono) o lo crea.
     * Se usa en los envíos con CSV de variables: así {{nombre}} funciona sin
     * tener que pasar antes por el módulo de Contactos.
     *
     * Si el contacto ya existe y venía sin nombre, se completa con el del CSV
     * (no se pisa un nombre que el usuario ya hubiera puesto).
     */
    public function firstOrCreate(?string $name, ?string $email, ?string $phone): array
    {
        $existing = $this->findByEmailOrPhone($email, $phone);
        if ($existing !== null) {
            if (empty($existing['name']) && !empty($name)) {
                $stmt = $this->pdo->prepare('UPDATE contacts SET name = :name WHERE id = :id');
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':id', $existing['id'], PDO::PARAM_INT);
                $stmt->execute();
                $existing['name'] = $name;
            }

            return $existing;
        }

        $id = $this->create($name, $email, $phone);

        return ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone];
    }

    /**
     * Busca un contacto por email o, si no hay email, por teléfono.
     * Se usa en la importación CSV para no crear duplicados.
     */
    public function findByEmailOrPhone(?string $email, ?string $phone): ?array
    {
        if ($email !== null && $email !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM contacts WHERE email = :email LIMIT 1');
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        } elseif ($phone !== null && $phone !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM contacts WHERE phone = :phone LIMIT 1');
            $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
        } else {
            return null;
        }

        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
