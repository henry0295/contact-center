<?php

namespace App\Service;

use App\Database\Connection;
use PDO;

/**
 * Saldo prepago (pesos colombianos) y su libro de movimientos.
 *
 * Reglas:
 *   - Los precios por canal viven en `credit_prices` y NUNCA salen hacia el
 *     frontend: la API solo expone el saldo (ver BalanceController).
 *   - El saldo solo SUBE desde la consola (bin/credits.php recharge). La app
 *     web solo lo BAJA, con el descuento automático de cada envío.
 *   - El descuento es atómico: un único UPDATE condicional
 *     (`balance >= importe`) dentro de una transacción, así dos procesos que
 *     envían a la vez (petición HTTP y dispatcher) nunca dejan el saldo en
 *     negativo ni cobran dos veces el mismo saldo.
 */
class CreditService
{
    /** Canales que se cobran. */
    public const CHANNELS = ['sms', 'email', 'call'];

    /** Etiquetas para mensajes y la consola. */
    public const LABELS = ['sms' => 'SMS', 'email' => 'Email', 'call' => 'Llamada de voz'];

    /** Por debajo de este % del saldo de referencia, el medidor avisa en rojo. */
    public const LOW_PERCENT = 20;

    private PDO $pdo;

    /** @var array<string,float>|null */
    private ?array $priceCache = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::get();
    }

    // ------------------------------------------------------------- lectura --

    /** @return array{balance:float, reference:float, updatedAt:?string} */
    public function account(): array
    {
        $row = $this->pdo->query('SELECT balance, gauge_reference, updated_at FROM credit_account WHERE id = 1')->fetch();
        if ($row === false) {
            return ['balance' => 0.0, 'reference' => 0.0, 'updatedAt' => null];
        }

        return [
            'balance'   => (float) $row['balance'],
            'reference' => (float) $row['gauge_reference'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    public function balance(): float
    {
        return $this->account()['balance'];
    }

    /** @return array<string,float> canal => precio unitario. USO INTERNO. */
    public function prices(): array
    {
        if ($this->priceCache !== null) {
            return $this->priceCache;
        }

        $prices = [];
        foreach ($this->pdo->query('SELECT channel, price FROM credit_prices')->fetchAll() as $row) {
            $prices[(string) $row['channel']] = (float) $row['price'];
        }

        return $this->priceCache = $prices;
    }

    public function price(string $channel): float
    {
        $this->assertChannel($channel);
        $prices = $this->prices();
        if (!isset($prices[$channel])) {
            throw new \RuntimeException('No hay precio configurado para el canal ' . $channel
                . '. Defínelo con: php bin/credits.php set-price ' . $channel . ' <valor>');
        }

        return $prices[$channel];
    }

    public function cost(string $channel, int $quantity): float
    {
        return round($this->price($channel) * max(0, $quantity), 2);
    }

    /**
     * Comprueba ANTES de enviar que el saldo cubre todo el lote. No reserva
     * nada (el cobro real es por envío, en debit()); evita empezar una
     * campaña que se quedaría a medias.
     *
     * @throws InsufficientCreditException
     */
    public function assertCanAfford(string $channel, int $quantity): void
    {
        $needed = $this->cost($channel, $quantity);
        $balance = $this->balance();
        if ($balance + 0.00001 < $needed) {
            // El mensaje NO incluye el costo: deduciría el precio unitario.
            throw new InsufficientCreditException(sprintf(
                'Saldo insuficiente para enviar a %d destinatario(s). Tu saldo es %s. '
                . 'Contacta al administrador para recargar.',
                $quantity,
                self::money($balance)
            ));
        }
    }

    // ---------------------------------------------------------- movimientos --

    /**
     * Descuenta el precio de UN envío. Devuelve false (sin tocar nada) si el
     * saldo no alcanza.
     */
    public function debit(string $channel, ?int $campaignId = null, ?string $recipient = null, int $quantity = 1): bool
    {
        $amount = $this->cost($channel, $quantity);
        if ($amount <= 0) {
            return true;
        }

        return $this->apply(function () use ($amount, $channel, $campaignId, $recipient, $quantity): bool {
            $stmt = $this->pdo->prepare(
                'UPDATE credit_account SET balance = balance - :amount WHERE id = 1 AND balance >= :min'
            );
            $stmt->bindValue(':amount', $amount);
            $stmt->bindValue(':min', $amount);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                return false;
            }
            $this->ledger('debit', $channel, $quantity, -$amount, $campaignId, $recipient, null, 'sistema');

            return true;
        });
    }

    /** Devuelve el cobro de un envío que finalmente no salió. */
    public function refund(string $channel, ?int $campaignId = null, ?string $recipient = null, ?string $note = null, int $quantity = 1): void
    {
        $amount = $this->cost($channel, $quantity);
        if ($amount <= 0) {
            return;
        }

        $this->apply(function () use ($amount, $channel, $campaignId, $recipient, $note, $quantity): bool {
            $stmt = $this->pdo->prepare('UPDATE credit_account SET balance = balance + :amount WHERE id = 1');
            $stmt->bindValue(':amount', $amount);
            $stmt->execute();
            $this->ledger('refund', $channel, $quantity, $amount, $campaignId, $recipient, $note, 'sistema');

            return true;
        });
    }

    /**
     * Recarga. SOLO desde la consola (bin/credits.php): no hay ruta HTTP que
     * llegue aquí. Fija también la referencia del medidor.
     */
    public function recharge(float $amount, ?string $note, string $actor): float
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException('El valor de la recarga debe ser mayor que 0.');
        }
        $amount = round($amount, 2);

        $this->apply(function () use ($amount, $note, $actor): bool {
            $stmt = $this->pdo->prepare(
                'UPDATE credit_account SET balance = balance + :amount, gauge_reference = balance WHERE id = 1'
            );
            $stmt->bindValue(':amount', $amount);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('No existe la cuenta de créditos. Aplica database/schema.sql.');
            }
            $this->ledger('recharge', null, 1, $amount, null, null, $note, $actor);

            return true;
        });

        return $this->balance();
    }

    /** Ajuste manual (positivo o negativo). Solo consola. */
    public function adjust(float $amount, ?string $note, string $actor): float
    {
        if (!is_finite($amount) || $amount == 0.0) {
            throw new \InvalidArgumentException('El ajuste no puede ser 0.');
        }
        $amount = round($amount, 2);

        $this->apply(function () use ($amount, $note, $actor): bool {
            $stmt = $this->pdo->prepare(
                'UPDATE credit_account SET balance = balance + :amount WHERE id = 1 AND balance + :check >= 0'
            );
            $stmt->bindValue(':amount', $amount);
            $stmt->bindValue(':check', $amount);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                throw new \InvalidArgumentException('El ajuste dejaría el saldo en negativo.');
            }
            $this->ledger('adjust', null, 1, $amount, null, null, $note, $actor);

            return true;
        });

        return $this->balance();
    }

    public function setPrice(string $channel, float $price): void
    {
        $this->assertChannel($channel);
        if (!is_finite($price) || $price < 0) {
            throw new \InvalidArgumentException('El precio debe ser 0 o mayor.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO credit_prices (channel, price) VALUES (:channel, :price)
             ON DUPLICATE KEY UPDATE price = VALUES(price)'
        );
        $stmt->bindValue(':channel', $channel);
        $stmt->bindValue(':price', round($price, 2));
        $stmt->execute();
        $this->priceCache = null;
    }

    /** Últimos movimientos (consola). */
    public function history(int $limit = 30, ?string $type = null): array
    {
        $sql = 'SELECT * FROM credit_transactions';
        if ($type !== null) {
            $sql .= ' WHERE type = :type';
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        if ($type !== null) {
            $stmt->bindValue(':type', $type);
        }
        $stmt->bindValue(':limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Consumo agregado por canal desde una fecha (consola). */
    public function usageSince(string $since): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT channel,
                    SUM(CASE WHEN type = 'debit' THEN quantity ELSE 0 END) -
                    SUM(CASE WHEN type = 'refund' THEN quantity ELSE 0 END) AS units,
                    -SUM(amount) AS spent
               FROM credit_transactions
              WHERE type IN ('debit', 'refund') AND created_at >= :since
              GROUP BY channel"
        );
        $stmt->bindValue(':since', $since);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function money(float $value): string
    {
        return '$' . number_format($value, 0, ',', '.');
    }

    // ------------------------------------------------------------- interno --

    private function assertChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('Canal inválido: ' . $channel . ' (sms, email o call).');
        }
    }

    /** Ejecuta $fn en una transacción propia (o en la que ya esté abierta). */
    private function apply(callable $fn): bool
    {
        if ($this->pdo->inTransaction()) {
            return (bool) $fn();
        }

        $this->pdo->beginTransaction();
        try {
            $ok = (bool) $fn();
            if ($ok) {
                $this->pdo->commit();
            } else {
                $this->pdo->rollBack();
            }

            return $ok;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ledger(
        string $type,
        ?string $channel,
        int $quantity,
        float $amount,
        ?int $campaignId,
        ?string $recipient,
        ?string $note,
        string $actor
    ): void {
        // Dentro de la misma transacción: el saldo leído es el que acaba de
        // quedar tras el UPDATE (la fila sigue bloqueada para los demás).
        $balanceAfter = (float) $this->pdo->query('SELECT balance FROM credit_account WHERE id = 1')->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO credit_transactions
                (type, channel, quantity, amount, balance_after, campaign_id, recipient, note, actor)
             VALUES (:type, :channel, :quantity, :amount, :balance_after, :campaign_id, :recipient, :note, :actor)'
        );
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':channel', $channel, $channel === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':quantity', $quantity, PDO::PARAM_INT);
        $stmt->bindValue(':amount', $amount);
        $stmt->bindValue(':balance_after', $balanceAfter);
        $stmt->bindValue(':campaign_id', $campaignId, $campaignId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':recipient', $recipient === null ? null : mb_substr($recipient, 0, 255), $recipient === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':note', $note === null ? null : mb_substr($note, 0, 255), $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':actor', mb_substr($actor, 0, 64));
        $stmt->execute();
    }
}
