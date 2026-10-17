<?php

/**
 * Genera secretos criptográficamente seguros y los escribe en el .env.
 *
 *   composer secrets               # genera todo lo que falte o sea débil
 *   php bin/generate-secrets.php --jwt
 *   php bin/generate-secrets.php --admin-password
 *   php bin/generate-secrets.php --db-password
 *   php bin/generate-secrets.php --all --force
 *
 * --force regenera incluso valores que ya parecen fuertes.
 * Deja una copia previa en .env.bak.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\EnvFile;

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$all = in_array('--all', $args, true) || empty(array_diff($args, ['--force']));

$want = [
    'jwt'      => $all || in_array('--jwt', $args, true),
    'admin'    => $all || in_array('--admin-password', $args, true),
    'db'       => $all || in_array('--db-password', $args, true),
];

/** Secreto hexadecimal (equivalente a openssl rand -hex N). */
function randomHex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

/** Contraseña fuerte y legible, sin caracteres que rompan un .env o una URL. */
function randomPassword(int $length = 24): string
{
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '-_';
    $all = $letters . $digits . $symbols;

    // Se garantiza al menos una letra y un dígito para que la contraseña pase
    // siempre la validación de robustez de la aplicación.
    $chars = [
        $letters[random_int(0, strlen($letters) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];
    for ($i = count($chars); $i < $length; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    // Mezcla con random_int (shuffle() no es criptográficamente seguro).
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

/** ¿Es un valor por defecto, vacío o demasiado corto? */
function isWeak(?string $value, int $minLength): bool
{
    if ($value === null || $value === '') {
        return true;
    }
    if (strlen($value) < $minLength) {
        return true;
    }
    foreach (['dev-', 'cambia', 'change', 'admin123', 'secret', 'password', 'test'] as $needle) {
        if (stripos($value, $needle) !== false) {
            return true;
        }
    }

    return false;
}

try {
    $env = new EnvFile();
    $changed = [];

    if ($want['jwt']) {
        if ($force || isWeak($env->get('JWT_SECRET'), 48)) {
            $env->set('JWT_SECRET', randomHex(32));
            $changed[] = 'JWT_SECRET (64 caracteres hex)';
        } else {
            echo "JWT_SECRET ya es fuerte; se conserva (usa --force para regenerarlo).\n";
        }
    }

    if ($want['admin']) {
        if ($force || isWeak($env->get('ADMIN_PASSWORD'), 16)) {
            $env->set('ADMIN_PASSWORD', randomPassword(24));
            $changed[] = 'ADMIN_PASSWORD (24 caracteres)';
        } else {
            echo "ADMIN_PASSWORD ya es fuerte; se conserva (usa --force para regenerarla).\n";
        }
    }

    if ($want['db']) {
        if ($force || isWeak($env->get('DB_PASSWORD'), 16)) {
            $env->set('DB_PASSWORD', randomPassword(28));
            $changed[] = 'DB_PASSWORD (28 caracteres)';
        } else {
            echo "DB_PASSWORD ya es fuerte; se conserva (usa --force para regenerarla).\n";
        }
    }

    if (empty($changed)) {
        echo "No había nada que cambiar.\n";
        exit(0);
    }

    $env->save();

    echo "Secretos escritos en {$env->path()} (copia previa en .env.bak):\n";
    foreach ($changed as $c) {
        echo "  - {$c}\n";
    }
    echo "\nLos valores NO se muestran aquí a propósito: ábrelos en el .env.\n";

    if (in_array('DB_PASSWORD (28 caracteres)', $changed, true)) {
        echo "\nIMPORTANTE: la contraseña de la base de datos solo se guardó en el .env.\n";
        echo "Aplícala en MySQL con:  composer db-user\n";
    }
    if (in_array('ADMIN_PASSWORD (24 caracteres)', $changed, true)) {
        echo "\nIMPORTANTE: aplica la nueva contraseña del admin con:  composer seed-admin\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
