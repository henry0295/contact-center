<?php

/**
 * Copia de seguridad de la base de datos con mysqldump.
 *
 *   composer backup
 *   php bin/backup-db.php --keep=14 --out=D:/backups
 *
 * Guarda un archivo .sql comprimido en storage/backups (o en --out) y conserva
 * las últimas --keep copias (7 por defecto).
 *
 * Requiere privilegios de lectura sobre todas las tablas: el usuario de la
 * aplicación (cc_app) NO sirve para volcar, hay que indicar uno administrativo
 * con --user / --password (por defecto root sin contraseña, como en XAMPP).
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$keep = 7;
$outDir = dirname(__DIR__) . '/storage/backups';
$user = 'root';
$password = '';

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int) $m[1]);
    } elseif (str_starts_with($arg, '--out=')) {
        $outDir = rtrim(substr($arg, 6), '/\\');
    } elseif (str_starts_with($arg, '--user=')) {
        $user = substr($arg, 7);
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, 11);
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$database = $_ENV['DB_DATABASE'] ?? 'contact_center';

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "ERROR: no se pudo crear la carpeta {$outDir}\n");
    exit(1);
}

/** Busca mysqldump en el PATH y en las rutas típicas de XAMPP. */
function findMysqldump(): ?string
{
    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/opt/lampp/bin/mysqldump',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    // Último recurso: confiar en el PATH.
    $which = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'where mysqldump' : 'which mysqldump';
    $found = trim((string) @shell_exec($which));
    $firstLine = trim(explode("\n", $found)[0] ?? '');

    return $firstLine !== '' && is_file($firstLine) ? $firstLine : null;
}

$mysqldump = findMysqldump();
if ($mysqldump === null) {
    fwrite(STDERR, "ERROR: no se encontró mysqldump. Añádelo al PATH.\n");
    exit(1);
}

$timestamp = date('Y-m-d_His');
$sqlFile = $outDir . DIRECTORY_SEPARATOR . "{$database}_{$timestamp}.sql";

// La contraseña se pasa por variable de entorno (MYSQL_PWD) en lugar de en la
// línea de comandos, donde sería visible para cualquier proceso del sistema.
$command = sprintf(
    '%s --host=%s --user=%s --single-transaction --routines --events --default-character-set=utf8mb4 %s',
    escapeshellarg($mysqldump),
    escapeshellarg($host),
    escapeshellarg($user),
    escapeshellarg($database)
);

$descriptors = [1 => ['file', $sqlFile, 'w'], 2 => ['pipe', 'w']];

// El entorno de proc_open solo admite cadenas: $_SERVER trae valores array
// (como argv) que provocaban un "Array to string conversion".
$env = array_filter($_SERVER, static fn ($v): bool => is_scalar($v));
$env = array_map('strval', $env);
if ($password !== '') {
    $env['MYSQL_PWD'] = $password;
}

$process = proc_open($command, $descriptors, $pipes, null, $env);
if (!is_resource($process)) {
    fwrite(STDERR, "ERROR: no se pudo ejecutar mysqldump.\n");
    exit(1);
}

$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$status = proc_close($process);

if ($status !== 0) {
    @unlink($sqlFile);
    fwrite(STDERR, "ERROR: mysqldump falló: {$stderr}\n");
    exit(1);
}

// Comprobación real: un volcado válido termina con el comentario de cierre.
$size = filesize($sqlFile) ?: 0;
if ($size < 1024) {
    @unlink($sqlFile);
    fwrite(STDERR, "ERROR: el volcado salió vacío o truncado.\n");
    exit(1);
}

// Compresión (reduce mucho el tamaño de un .sql).
$gzFile = $sqlFile . '.gz';
$in = fopen($sqlFile, 'rb');
$gz = gzopen($gzFile, 'wb9');
if ($in && $gz) {
    while (!feof($in)) {
        gzwrite($gz, (string) fread($in, 262144));
    }
    fclose($in);
    gzclose($gz);
    unlink($sqlFile);
    $final = $gzFile;
} else {
    $final = $sqlFile;
}

printf(
    "Copia creada: %s (%.1f KB)\n",
    $final,
    (filesize($final) ?: 0) / 1024
);

// Rotación: se conservan las últimas $keep copias.
$existing = glob($outDir . DIRECTORY_SEPARATOR . "{$database}_*.sql*") ?: [];
usort($existing, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

$removed = 0;
foreach (array_slice($existing, $keep) as $old) {
    if (@unlink($old)) {
        $removed++;
    }
}
if ($removed > 0) {
    echo "Copias antiguas eliminadas: {$removed} (se conservan {$keep}).\n";
}

echo "Total de copias disponibles: " . min(count($existing), $keep) . "\n";
