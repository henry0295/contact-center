<?php

/**
 * Créditos (saldo prepago en pesos). ÚNICA forma de recargar saldo y de ver o
 * cambiar precios: no existe ningún endpoint HTTP para ello.
 *
 * En el servidor (como el usuario web, que lee el .env):
 *   sudo -u nginx php /var/www/contact-center/bin/credits.php balance
 *   sudo -u nginx php /var/www/contact-center/bin/credits.php recharge 100000 --note="Factura 123"
 *   sudo -u nginx php /var/www/contact-center/bin/credits.php history --limit=20
 *   sudo -u nginx php /var/www/contact-center/bin/credits.php prices
 *   sudo -u nginx php /var/www/contact-center/bin/credits.php set-price sms 27
 *   sudo -u nginx php /var/www/contact-center/bin/credits.php adjust -5000 --note="Corrección"
 *   sudo -u nginx php /var/www/contact-center/bin/credits.php usage --since=2026-10-01
 * (En Debian/Ubuntu el usuario web es www-data.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Service\CreditService;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

$argvList = array_slice($argv, 1);
$command = $argvList[0] ?? 'help';
$positional = [];
$options = [];
foreach (array_slice($argvList, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m)) {
        $options[$m[1]] = '1';
    } else {
        $positional[] = $arg;
    }
}

/** Número tipo 100000 o 1500.50 (sin separadores de miles). */
function parseAmount(?string $raw, bool $allowNegative = false): float
{
    $raw = trim((string) $raw);
    $pattern = $allowNegative ? '/^-?\d+(\.\d{1,2})?$/' : '/^\d+(\.\d{1,2})?$/';
    if (!preg_match($pattern, $raw)) {
        fwrite(STDERR, "ERROR: valor inválido '{$raw}'. Usa solo dígitos, p.ej. 100000 o 1500.50 (sin puntos de miles).\n");
        exit(1);
    }

    return (float) $raw;
}

function actor(): string
{
    $user = getenv('SUDO_USER') ?: '';
    if ($user === '' && function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        $user = is_array($info) ? (string) $info['name'] : '';
    }
    if ($user === '') {
        $user = get_current_user() ?: 'consola';
    }

    return 'cli:' . $user;
}

function usage(): void
{
    echo <<<TXT
Uso: php bin/credits.php <comando> [opciones]

  balance                          Saldo actual
  recharge <valor> [--note=...]    Recarga saldo (pesos)
  adjust <±valor> [--note=...]     Ajuste manual (positivo o negativo)
  history [--limit=30] [--type=recharge|debit|refund|adjust]
  prices                           Precio por canal
  set-price <sms|email|call> <valor>
  usage [--since=AAAA-MM-DD]       Consumo por canal (por defecto, este mes)

TXT;
}

try {
    $credits = new CreditService();

    switch ($command) {
        case 'balance':
            $acc = $credits->account();
            printf("Saldo: %s COP (referencia del medidor: %s)\n", CreditService::money($acc['balance']), CreditService::money($acc['reference']));
            break;

        case 'recharge':
            $amount = parseAmount($positional[0] ?? null);
            $new = $credits->recharge($amount, $options['note'] ?? null, actor());
            printf("Recarga de %s aplicada. Saldo: %s COP\n", CreditService::money($amount), CreditService::money($new));
            break;

        case 'adjust':
            $amount = parseAmount($positional[0] ?? null, true);
            $new = $credits->adjust($amount, $options['note'] ?? null, actor());
            printf("Ajuste de %s aplicado. Saldo: %s COP\n", ($amount < 0 ? '-' : '') . CreditService::money(abs($amount)), CreditService::money($new));
            break;

        case 'history':
            $type = $options['type'] ?? null;
            if ($type !== null && !in_array($type, ['recharge', 'debit', 'refund', 'adjust'], true)) {
                throw new InvalidArgumentException('--type debe ser recharge, debit, refund o adjust.');
            }
            $rows = $credits->history((int) ($options['limit'] ?? 30), $type);
            if (empty($rows)) {
                echo "Sin movimientos.\n";
                break;
            }
            printf("%-19s  %-8s  %-6s  %12s  %12s  %-8s  %s\n", 'Fecha', 'Tipo', 'Canal', 'Valor', 'Saldo', 'Campaña', 'Detalle');
            foreach (array_reverse($rows) as $r) {
                $amount = (float) $r['amount'];
                printf(
                    "%-19s  %-8s  %-6s  %12s  %12s  %-8s  %s\n",
                    $r['created_at'],
                    $r['type'],
                    $r['channel'] ?? '-',
                    ($amount < 0 ? '-' : '+') . CreditService::money(abs($amount)),
                    CreditService::money((float) $r['balance_after']),
                    $r['campaign_id'] ?? '-',
                    trim(($r['recipient'] ?? '') . ' ' . ($r['note'] ?? '') . ' ' . ($r['actor'] && $r['actor'] !== 'sistema' ? '[' . $r['actor'] . ']' : ''))
                );
            }
            break;

        case 'prices':
            foreach (CreditService::CHANNELS as $ch) {
                $prices = $credits->prices();
                printf("  %-16s %s\n", CreditService::LABELS[$ch], isset($prices[$ch]) ? CreditService::money($prices[$ch]) : '(sin precio)');
            }
            break;

        case 'set-price':
            $channel = (string) ($positional[0] ?? '');
            $price = parseAmount($positional[1] ?? null);
            $credits->setPrice($channel, $price);
            printf("Precio de %s: %s\n", CreditService::LABELS[$channel] ?? $channel, CreditService::money($price));
            break;

        case 'usage':
            $since = $options['since'] ?? date('Y-m-01');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
                throw new InvalidArgumentException('--since debe tener formato AAAA-MM-DD.');
            }
            $rows = $credits->usageSince($since . ' 00:00:00');
            echo "Consumo desde {$since}:\n";
            $total = 0.0;
            foreach ($rows as $r) {
                printf("  %-16s %8d envíos  %12s\n", CreditService::LABELS[$r['channel']] ?? $r['channel'], (int) $r['units'], CreditService::money((float) $r['spent']));
                $total += (float) $r['spent'];
            }
            printf("  %-16s %8s         %12s\n", 'Total', '', CreditService::money($total));
            break;

        case 'help':
        case '--help':
        case '-h':
            usage();
            break;

        default:
            usage();
            exit(1);
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "¿Está MariaDB arrancado y aplicado database/schema.sql?\n");
    exit(1);
}
