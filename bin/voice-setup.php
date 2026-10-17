<?php

/**
 * Crea (o recupera) el usuario de ESTA instalación en la API de voz y guarda
 * su API key en el .env. Lo ejecuta deploy.sh; también sirve a mano.
 *
 *   VOICE_ADMIN_KEY='<clave admin de la API de voz>' php bin/voice-setup.php [--url=http://host:8000]
 *
 * La API de voz (/opt/apivoz, Node.js) expone:
 *   POST /auth/register {username, password, company, admin_key} -> {api_key}
 *   POST /auth/login    {username, password}                     -> {api_key}
 *   GET  /auth/me       (cabecera X-API-Key)                     -> 200 si la key es válida
 *
 * Orden: (1) si VOICE_API_KEY ya es válida, no hace nada; (2) si hay
 * VOICE_API_USER/VOICE_API_PASSWORD, recupera la key con login; (3) si no,
 * registra un usuario nuevo con la clave de administrador.
 *
 * La clave de administrador se lee SOLO de la variable de entorno
 * VOICE_ADMIN_KEY (no de argv, que se ve en `ps`) y nunca se guarda.
 *
 * Códigos de salida: 0 = listo, 2 = falta la clave de administrador,
 * 1 = error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Support\EnvFile;
use GuzzleHttp\Client;

const DEFAULT_VOICE_URL = 'http://indoor-qvmdkkckkpc.dynamic-m.com:8000';

$opts = getopt('', ['url::', 'username::', 'force', 'env-file::']);

function out(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $msg . "\n");
    exit($code);
}

/** Petición JSON sin lanzar excepciones por códigos 4xx/5xx. */
function call(Client $http, string $method, string $path, ?array $json = null, array $headers = []): array
{
    try {
        $res = $http->request($method, ltrim($path, '/'), array_filter([
            'json'        => $json,
            'headers'     => $headers + ['Accept' => 'application/json'],
            'http_errors' => false,
        ], static fn ($v) => $v !== null));
    } catch (\Throwable $e) {
        fail('No se pudo conectar con la API de voz: ' . $e->getMessage());
    }
    $data = json_decode((string) $res->getBody(), true);

    return [$res->getStatusCode(), is_array($data) ? $data : []];
}

function validKey(string $key): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_\-]{16,200}$/', $key);
}

function randomPassword(int $length = 24): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $out;
}

try {
    $env = new EnvFile(isset($opts['env-file']) && $opts['env-file'] !== '' ? (string) $opts['env-file'] : null);
} catch (\Throwable $e) {
    fail($e->getMessage());
}

$url = (string) ($opts['url'] ?? '') ?: ((string) $env->get('VOICE_API_URL') ?: DEFAULT_VOICE_URL);
$url = rtrim(trim($url, " \t\"'"), '/');
if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._\-/]*)?$#', $url)) {
    fail('VOICE_API_URL no válida: ' . $url);
}

$http = new Client(['base_uri' => $url . '/', 'timeout' => 20]);

[$code, $root] = call($http, 'GET', '/');
if ($code !== 200) {
    fail("La API de voz en {$url} no responde correctamente (HTTP {$code}).");
}
out("API de voz OK en {$url}");

$changed = false;
if ($env->get('VOICE_API_URL') !== $url) {
    $env->set('VOICE_API_URL', $url);
    $changed = true;
}

// 1) ¿La key actual sigue siendo válida?
$currentKey = trim((string) $env->get('VOICE_API_KEY'), " \"'");
if ($currentKey !== '' && !isset($opts['force'])) {
    [$code] = call($http, 'GET', '/auth/me', null, ['X-API-Key' => $currentKey]);
    if ($code === 200) {
        if ($changed) {
            $env->save();
            @unlink($env->path() . '.bak');
        }
        out('VOICE_API_KEY ya configurada y válida; no se cambia nada.');
        exit(0);
    }
    out("La VOICE_API_KEY actual no es válida (HTTP {$code}); se obtiene una nueva.");
}

$apiKey = null;

// 2) Usuario ya creado antes: login.
$user = trim((string) $env->get('VOICE_API_USER'), " \"'");
$pass = trim((string) $env->get('VOICE_API_PASSWORD'), " \"'");
if ($user !== '' && $pass !== '' && !isset($opts['force'])) {
    [$code, $data] = call($http, 'POST', '/auth/login', ['username' => $user, 'password' => $pass]);
    if ($code === 200 && validKey((string) ($data['api_key'] ?? ''))) {
        $apiKey = (string) $data['api_key'];
        out("API key recuperada con el usuario {$user}.");
    } else {
        out("No se pudo iniciar sesión con {$user} (HTTP {$code}); se registrará un usuario nuevo.");
    }
}

// 3) Registrar un usuario nuevo.
if ($apiKey === null) {
    $adminKey = (string) getenv('VOICE_ADMIN_KEY');
    if ($adminKey === '') {
        fail('Falta la clave de administrador de la API de voz (variable VOICE_ADMIN_KEY).', 2);
    }

    $host = preg_replace('/[^a-z0-9\-]/', '-', strtolower((string) gethostname())) ?: 'servidor';
    $user = (string) ($opts['username'] ?? '') ?: ('contact-center-' . trim($host, '-') . '-' . bin2hex(random_bytes(2)));
    if (!preg_match('/^[A-Za-z0-9._\-]{3,64}$/', $user)) {
        fail('Nombre de usuario no válido: ' . $user);
    }
    $pass = randomPassword();

    [$code, $data] = call($http, 'POST', '/auth/register', [
        'username'  => $user,
        'password'  => $pass,
        'company'   => 'Contact Center',
        'admin_key' => $adminKey,
    ]);
    if ($code === 403) {
        fail('La API de voz rechazó la clave de administrador (HTTP 403).', 2);
    }
    if ($code !== 200 || !validKey((string) ($data['api_key'] ?? ''))) {
        fail("No se pudo crear el usuario en la API de voz (HTTP {$code}): " . ($data['error'] ?? 'respuesta inesperada'));
    }
    $apiKey = (string) $data['api_key'];
    $env->set('VOICE_API_USER', $user);
    $env->set('VOICE_API_PASSWORD', $pass);
    out("Usuario {$user} creado en la API de voz.");
}

// Comprobación final.
[$code] = call($http, 'GET', '/auth/me', null, ['X-API-Key' => $apiKey]);
if ($code !== 200) {
    fail("La API key obtenida no funciona (HTTP {$code}).");
}

$env->set('VOICE_API_KEY', $apiKey);
$env->save();
// La copia .env.bak contiene secretos: no se deja.
@unlink($env->path() . '.bak');

out('VOICE_API_KEY guardada en ' . $env->path() . ' (valor no mostrado).');
exit(0);
