<?php

/**
 * Siembra (o actualiza) el usuario admin inicial a partir de ADMIN_EMAIL y
 * ADMIN_PASSWORD del .env. Idempotente: puede ejecutarse varias veces.
 *
 *   composer seed-admin
 *   php bin/seed-admin.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Repositories\UserRepository;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$email = $_ENV['ADMIN_EMAIL'] ?? '';
$password = $_ENV['ADMIN_PASSWORD'] ?? '';
$name = $_ENV['ADMIN_NAME'] ?? 'Administrador';

if ($email === '' || $password === '') {
    fwrite(STDERR, "ERROR: define ADMIN_EMAIL y ADMIN_PASSWORD en el .env antes de sembrar.\n");
    exit(1);
}

try {
    $users = new UserRepository();
    $users->upsert($name, $email, password_hash($password, PASSWORD_BCRYPT), 'admin');
    echo "Admin sembrado/actualizado: {$email}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR al sembrar el admin: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "¿Está MySQL arrancado en XAMPP y creada la BD (database/schema.sql)?\n");
    exit(1);
}
