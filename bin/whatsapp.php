<?php

/**
 * Activación y administración de la línea de WhatsApp (Gupshup Partner API)
 * de ESTA instalación. Lo ejecuta el administrador de Vozip (o deploy.sh).
 *
 * Las credenciales de PARTNER (administran a todos los clientes) se leen de
 * variables de entorno y NUNCA se guardan en este servidor. En el .env solo
 * queda el token de la app de este cliente (GUPSHUP_APP_ID / GUPSHUP_APP_TOKEN).
 *
 *   export GUPSHUP_PARTNER_EMAIL='partner@vozip.com.co'
 *   read -rs GUPSHUP_PARTNER_SECRET; export GUPSHUP_PARTNER_SECRET
 *
 *   php bin/whatsapp.php activate --name=ClienteXYZ   crea (o reutiliza) la app, genera el
 *                                                     enlace de registro, guarda el token y
 *                                                     registra el webhook
 *   php bin/whatsapp.php link [--regenerate]          enlace de registro (Embedded Signup)
 *   php bin/whatsapp.php token                        vuelve a obtener el token de la app
 *   php bin/whatsapp.php subscribe                    registra el webhook (necesita APP_URL https)
 *   php bin/whatsapp.php status                       estado de la línea (solo token de app)
 *
 * Opción común: --env-file=ruta
 * Salida: 0 = ok, 2 = faltan datos / credenciales inválidas, 1 = error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Service\WhatsAppService;
use App\Support\EnvFile;
use GuzzleHttp\Client;

function out(string $m): void { fwrite(STDOUT, $m . "\n"); }
function fail(string $m, int $code = 1): never { fwrite(STDERR, 'ERROR: ' . $m . "\n"); exit($code); }

// getopt() se detiene en el primer argumento posicional (el comando): se parsea a mano.
$command = 'help';
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } elseif ($command === 'help') {
        $command = $arg;
    }
}

try {
    $env = new EnvFile(isset($opts['env-file']) && $opts['env-file'] !== '' ? (string) $opts['env-file'] : null);
} catch (\Throwable $e) {
    fail($e->getMessage());
}

$base = rtrim((string) (getenv('GUPSHUP_BASE_URL') ?: envValue($env, 'GUPSHUP_BASE_URL') ?: WhatsAppService::BASE_URL), '/');
$http = new Client(['timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false]);

function envValue(EnvFile $env, string $key): string
{
    return trim((string) $env->get($key), " \"'");
}

/** @return array{0:int,1:array,2:string} */
function call(Client $http, string $method, string $url, array $options = []): array
{
    $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
    try {
        $res = $http->request($method, $url, $options);
    } catch (\Throwable $e) {
        fail('No se pudo conectar con Gupshup (' . parse_url($url, PHP_URL_HOST) . '): ' . $e->getMessage());
    }
    $raw = (string) $res->getBody();
    $data = json_decode($raw, true);

    return [$res->getStatusCode(), is_array($data) ? $data : [], $raw];
}

function apiError(array $data, string $raw, int $code): string
{
    $msg = $data['message'] ?? $data['error'] ?? null;
    if (is_array($msg)) {
        $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
    }
    $msg = trim((string) ($msg ?? '')) ?: mb_substr(strip_tags($raw), 0, 200);

    return 'HTTP ' . $code . ': ' . ($msg !== '' ? $msg : 'sin respuesta');
}

/** Token de partner (24 h). Las credenciales vienen solo del entorno. */
function partnerToken(Client $http, string $base): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }
    $email = trim((string) getenv('GUPSHUP_PARTNER_EMAIL'));
    $secret = trim((string) getenv('GUPSHUP_PARTNER_SECRET'));
    if ($email === '' || $secret === '') {
        fail('Faltan GUPSHUP_PARTNER_EMAIL y GUPSHUP_PARTNER_SECRET en el entorno (no se guardan en el servidor).', 2);
    }
    [$code, $data, $raw] = call($http, 'POST', $base . '/partner/account/login', [
        'form_params' => ['email' => $email, 'secret' => $secret],
    ]);
    if ($code >= 400 || empty($data['token'])) {
        fail('Gupshup rechazó las credenciales de partner (' . apiError($data, $raw, $code) . ').', 2);
    }

    return $token = (string) $data['token'];
}

function requireAppId(EnvFile $env): string
{
    $appId = envValue($env, 'GUPSHUP_APP_ID');
    if ($appId === '') {
        fail('No hay GUPSHUP_APP_ID en el .env. Ejecuta primero: php bin/whatsapp.php activate --name=<cliente>', 2);
    }

    return $appId;
}

function cmdCreateApp(Client $http, string $base, EnvFile $env, string $name): string
{
    $appId = envValue($env, 'GUPSHUP_APP_ID');
    if ($appId !== '') {
        out("App de Gupshup existente: {$appId} (se reutiliza).");

        return $appId;
    }
    // Gupshup: 6-150 caracteres, solo letras y números.
    $name = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '';
    if (strlen($name) < 6) {
        $name = str_pad($name, 6, '0');
    }
    $name = substr($name, 0, 150);

    [$code, $data, $raw] = call($http, 'POST', $base . '/partner/app', [
        'headers'     => ['token' => partnerToken($http, $base)],
        'form_params' => ['name' => $name, 'templateMessaging' => 'true'],
    ]);
    $appId = (string) ($data['appId'] ?? $data['app']['id'] ?? '');
    if ($code >= 400 || $appId === '') {
        fail('No se pudo crear la app "' . $name . '" (' . apiError($data, $raw, $code) . ').');
    }
    $env->set('GUPSHUP_APP_ID', $appId);
    $env->save();
    out("App de Gupshup creada: {$name} ({$appId}).");

    return $appId;
}

function cmdLink(Client $http, string $base, EnvFile $env, bool $regenerate, string $user, string $lang): string
{
    $appId = requireAppId($env);
    [$code, $data, $raw] = call($http, 'GET', $base . '/partner/app/' . rawurlencode($appId) . '/onboarding/embed/link', [
        'headers' => ['token' => partnerToken($http, $base)],
        'query'   => ['regenerate' => $regenerate ? 'true' : 'false', 'user' => $user, 'lang' => $lang],
    ]);
    $link = (string) ($data['link'] ?? '');
    if ($code >= 400 || $link === '') {
        fail('No se pudo generar el enlace de registro (' . apiError($data, $raw, $code) . ').');
    }
    $env->set('WHATSAPP_ONBOARDING_LINK', $link);
    $env->save();
    out('Enlace de registro (válido 5 días; ábrelo con la cuenta de Facebook del cliente):');
    out('  ' . $link);

    return $link;
}

function cmdToken(Client $http, string $base, EnvFile $env): void
{
    $appId = requireAppId($env);
    [$code, $data, $raw] = call($http, 'GET', $base . '/partner/app/' . rawurlencode($appId) . '/token/', [
        'headers' => ['Authorization' => partnerToken($http, $base)],
    ]);
    $token = (string) ($data['token']['token'] ?? '');
    if ($code >= 400 || $token === '') {
        fail('No se pudo obtener el token de la app (' . apiError($data, $raw, $code) . ').');
    }
    $env->set('GUPSHUP_APP_TOKEN', $token);
    $env->save();
    out('Token de la app guardado en ' . $env->path() . ' (no se muestra).');
}

function ensureWebhookSecret(EnvFile $env): string
{
    $secret = envValue($env, 'WHATSAPP_WEBHOOK_SECRET');
    if (strlen($secret) < 24) {
        $secret = bin2hex(random_bytes(24));
        $env->set('WHATSAPP_WEBHOOK_SECRET', $secret);
        $env->save();
        out('WHATSAPP_WEBHOOK_SECRET generado.');
    }

    return $secret;
}

/** @return bool true si quedó registrado */
function cmdSubscribe(Client $http, string $base, EnvFile $env): bool
{
    $appId = requireAppId($env);
    $token = envValue($env, 'GUPSHUP_APP_TOKEN');
    if ($token === '') {
        fail('Falta GUPSHUP_APP_TOKEN. Ejecuta: php bin/whatsapp.php token', 2);
    }
    $appUrl = rtrim(envValue($env, 'APP_URL'), '/');
    if (!str_starts_with($appUrl, 'https://')) {
        out('Aviso: APP_URL no es https (' . ($appUrl ?: 'vacío') . '). Gupshup solo entrega webhooks a URLs https públicas;');
        out('       configura el dominio con certificado y vuelve a ejecutar: php bin/whatsapp.php subscribe');

        return false;
    }
    $secret = ensureWebhookSecret($env);
    $url = $appUrl . '/api/webhooks/whatsapp';

    $headers = ['Authorization' => $token];
    $subsUrl = $base . '/partner/app/' . rawurlencode($appId) . '/subscription';

    // Quita suscripciones previas con el mismo tag para no duplicar eventos.
    [$code, $data] = call($http, 'GET', $subsUrl, ['headers' => $headers]);
    foreach (($code < 400 ? ($data['subscriptions'] ?? []) : []) as $s) {
        if (($s['tag'] ?? '') === 'contactcenter' && !empty($s['id'])) {
            call($http, 'DELETE', $subsUrl . '/' . rawurlencode((string) $s['id']), ['headers' => $headers]);
        }
    }

    [$code, $data, $raw] = call($http, 'POST', $subsUrl, [
        'headers'     => $headers,
        'form_params' => [
            'modes'   => 'SENT,DELIVERED,READ,FAILED,MESSAGE,TEMPLATE',
            'tag'     => 'contactcenter',
            'url'     => $url,
            'version' => '3',
            'meta'    => json_encode(['X-Gupshup-Webhook-Secret' => $secret]),
        ],
    ]);
    if ($code >= 400 || ($data['status'] ?? 'success') === 'error') {
        fail('No se pudo registrar el webhook (' . apiError($data, $raw, $code) . ').');
    }
    out('Webhook registrado: ' . $url);

    return true;
}

function cmdStatus(EnvFile $env): int
{
    foreach (['GUPSHUP_APP_ID', 'GUPSHUP_APP_TOKEN', 'GUPSHUP_BASE_URL', 'WHATSAPP_WEBHOOK_SECRET', 'APP_URL'] as $k) {
        $v = envValue($env, $k);
        if ($v !== '') {
            $_ENV[$k] = $v;
        }
    }
    $svc = new WhatsAppService();
    if (!$svc->isConfigured()) {
        out('Línea de WhatsApp: NO activada (faltan GUPSHUP_APP_ID / GUPSHUP_APP_TOKEN).');
        $link = envValue($env, 'WHATSAPP_ONBOARDING_LINK');
        if ($link !== '') {
            out('Enlace de registro pendiente: ' . $link);
        }

        return 2;
    }
    out('App de Gupshup: ' . WhatsAppService::appId());
    try {
        $h = $svc->health();
        out('Estado: ' . ($h['healthy'] ? 'activa' : 'con problemas — ' . $h['message']));
        $r = $svc->ratings();
        if ($r['quality'] !== null || $r['limit'] !== null) {
            out('Calidad: ' . ($r['quality'] ?? '-') . ' · Límite: ' . ($r['limit'] ?? '-'));
        } elseif ($r['message']) {
            out('Calidad: ' . $r['message']);
        }
        $tpls = $svc->listTemplates();
        $approved = count(array_filter($tpls, static fn ($t) => strtoupper((string) ($t['status'] ?? '')) === 'APPROVED'));
        out('Plantillas: ' . count($tpls) . ' (' . $approved . ' aprobadas)');
    } catch (\Throwable $e) {
        fail($e->getMessage());
    }
    out('Webhook secret: ' . (strlen(envValue($env, 'WHATSAPP_WEBHOOK_SECRET')) >= 24 ? 'configurado' : 'FALTA (ejecuta subscribe)'));

    return 0;
}

$lang = isset($opts['lang']) && preg_match('/^[a-z]{2}$/', (string) $opts['lang']) ? (string) $opts['lang'] : 'es';

switch ($command) {
    case 'activate':
        $name = trim((string) ($opts['name'] ?? ''));
        if ($name === '' && envValue($env, 'GUPSHUP_APP_ID') === '') {
            fail('Indica el nombre de la app: --name=<cliente> (6-150 letras/números).', 2);
        }
        cmdCreateApp($http, $base, $env, $name);
        cmdLink($http, $base, $env, isset($opts['regenerate']), $name !== '' ? $name : 'cliente', $lang);
        cmdToken($http, $base, $env);
        ensureWebhookSecret($env);
        cmdSubscribe($http, $base, $env);
        @unlink($env->path() . '.bak');
        out('');
        out('Siguiente paso: el cliente abre el enlace, inicia sesión en Facebook, elige su portafolio');
        out('y verifica su número. Después: php bin/whatsapp.php status');
        exit(0);

    case 'link':
        cmdLink($http, $base, $env, isset($opts['regenerate']), trim((string) ($opts['name'] ?? '')) ?: 'cliente', $lang);
        @unlink($env->path() . '.bak');
        exit(0);

    case 'token':
        cmdToken($http, $base, $env);
        @unlink($env->path() . '.bak');
        exit(0);

    case 'subscribe':
        $ok = cmdSubscribe($http, $base, $env);
        @unlink($env->path() . '.bak');
        exit($ok ? 0 : 2);

    case 'status':
        exit(cmdStatus($env));

    default:
        out('Uso: php bin/whatsapp.php activate --name=<cliente> | link [--regenerate] | token | subscribe | status');
        out('Las operaciones de partner requieren GUPSHUP_PARTNER_EMAIL y GUPSHUP_PARTNER_SECRET en el entorno.');
        exit($command === 'help' ? 0 : 2);
}
