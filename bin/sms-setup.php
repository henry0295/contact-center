<?php

/**
 * Configura el proveedor de SMS (IT Cloud Services / contacto-virtual.com)
 * de ESTA instalación: valida el email y el token de la cuenta del cliente
 * con la consulta de saldo del proveedor y los guarda en el .env.
 * Lo ejecuta deploy.sh; también sirve a mano.
 *
 *   SMS_SETUP_EMAIL='usuario@cliente.com' SMS_SETUP_TOKEN='<token>' php bin/sms-setup.php
 *   php bin/sms-setup.php            # sin variables: solo comprueba lo que ya hay
 *
 * El token se crea en la plataforma del proveedor (Configuraciones). Las
 * credenciales se leen de variables de entorno, no de argv (que se ve en ps).
 *
 * Opciones: --no-check  guarda sin validar (cuentas administradoras, que no
 *                       admiten la consulta de saldo)
 *           --env-file=ruta
 *
 * Salida: 0 = configurado y válido, 2 = faltan credenciales o son inválidas,
 *         1 = error (red, .env...).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Service\SmsService;
use App\Support\EnvFile;
use GuzzleHttp\Client;

$opts = getopt('', ['no-check', 'env-file::']);

function out(string $m): void { fwrite(STDOUT, $m . "\n"); }
function fail(string $m, int $code = 1): never { fwrite(STDERR, 'ERROR: ' . $m . "\n"); exit($code); }

/**
 * Consulta el saldo del proveedor. Devuelve el saldo (float), false si las
 * credenciales son inválidas (-1) o null si la respuesta no se entiende.
 */
function providerBalance(string $email, string $token): float|false|null
{
    $http = new Client(['timeout' => 20, 'connect_timeout' => 10]);
    try {
        $res = $http->get(SmsService::ITCLOUD_BALANCE_URL, [
            'query'       => ['user' => $email, 'token' => $token],
            'http_errors' => false,
        ]);
    } catch (\Throwable $e) {
        fail('No se pudo conectar con el proveedor de SMS (' . parse_url(SmsService::ITCLOUD_BALANCE_URL, PHP_URL_HOST) . '): ' . $e->getMessage());
    }
    $raw = trim((string) $res->getBody());
    $json = json_decode($raw, true);
    $value = is_array($json) ? ($json['saldo'] ?? $json['balance'] ?? $json['data'] ?? null) : $raw;
    if (is_numeric($value)) {
        return (float) $value == -1.0 ? false : (float) $value;
    }

    return null;
}

try {
    $env = new EnvFile(isset($opts['env-file']) && $opts['env-file'] !== '' ? (string) $opts['env-file'] : null);
} catch (\Throwable $e) {
    fail($e->getMessage());
}

$email = trim((string) getenv('SMS_SETUP_EMAIL'));
$token = trim((string) getenv('SMS_SETUP_TOKEN'));
$fromEnvFile = false;
if ($email === '' && $token === '') {
    $email = trim((string) $env->get('ITCLOUD_SMS_EMAIL'), " \"'");
    $token = trim((string) $env->get('ITCLOUD_SMS_TOKEN'), " \"'");
    $fromEnvFile = true;
}

if ($email === '' || $token === '') {
    fail('Faltan el email y el token del proveedor de SMS.', 2);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('El email del proveedor de SMS no es válido: ' . $email, 2);
}
if (!preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $token)) {
    fail('El token tiene un formato inválido (solo letras, números, _ y -).', 2);
}

if (!isset($opts['no-check'])) {
    $balance = providerBalance($email, $token);
    if ($balance === false) {
        fail('El proveedor rechazó el email o el token (código -1). Si es una cuenta administradora, '
            . 'que no admite la consulta de saldo, usa --no-check.', 2);
    }
    if ($balance === null) {
        out('Aviso: el proveedor respondió de forma inesperada a la consulta de saldo; se guardan las credenciales sin validar.');
    } else {
        out('Credenciales válidas. Saldo en el proveedor de SMS: ' . number_format($balance, 0, ',', '.'));
    }
}

if ($fromEnvFile) {
    out('Proveedor de SMS ya configurado; no se cambia nada.');
    exit(0);
}

$env->set('ITCLOUD_SMS_EMAIL', $email);
$env->set('ITCLOUD_SMS_TOKEN', $token);
if ($env->get('ITCLOUD_SMS_TYPE') === null || $env->get('ITCLOUD_SMS_TYPE') === '') {
    $env->set('ITCLOUD_SMS_TYPE', '1via');
}
$env->save();
@unlink($env->path() . '.bak');

out('Proveedor de SMS guardado en ' . $env->path() . ' (token no mostrado).');
exit(0);
