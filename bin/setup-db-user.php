<?php

/**
 * Crea (o actualiza) el usuario de MySQL dedicado a la aplicación, con permisos
 * solo sobre su base de datos. Toma el usuario y la contraseña del .env.
 *
 *   composer db-user
 *   php bin/setup-db-user.php --admin-user=root --admin-password=
 *
 * Usar un usuario propio en lugar de root es una práctica básica: si la
 * aplicación se ve comprometida, el atacante no obtiene control del servidor
 * de base de datos completo.
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Credenciales administrativas para poder crear el usuario (por defecto root).
$adminUser = 'root';
$adminPass = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--admin-user=')) {
        $adminUser = substr($arg, 13);
    }
    if (str_starts_with($arg, '--admin-password=')) {
        $adminPass = substr($arg, 17);
    }
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$database = $_ENV['DB_DATABASE'] ?? 'contact_center';
$appUser = $_ENV['DB_USERNAME'] ?? '';
$appPass = $_ENV['DB_PASSWORD'] ?? '';

if ($appUser === '' || strtolower($appUser) === 'root') {
    fwrite(STDERR, "ERROR: DB_USERNAME no debe ser 'root'. Pon un usuario propio en el .env (p.ej. cc_app).\n");
    exit(1);
}
if ($appPass === '') {
    fwrite(STDERR, "ERROR: DB_PASSWORD está vacío. Genera una con: composer secrets\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;charset=utf8mb4', $host),
        $adminUser,
        $adminPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // El identificador no admite parámetros preparados: se valida su forma.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $appUser) || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new \RuntimeException('El nombre de usuario y de base de datos solo admiten letras, números y _');
    }

    foreach (['localhost', '127.0.0.1'] as $fromHost) {
        $stmt = $pdo->prepare("CREATE USER IF NOT EXISTS ?@? IDENTIFIED BY ?");
        $stmt->execute([$appUser, $fromHost, $appPass]);

        // Asegura la contraseña también si el usuario ya existía.
        $stmt = $pdo->prepare("ALTER USER ?@? IDENTIFIED BY ?");
        $stmt->execute([$appUser, $fromHost, $appPass]);

        // Permisos mínimos para la aplicación: nada de DROP, GRANT ni FILE.
        $pdo->exec(sprintf(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `%s`.* TO %s@%s',
            $database,
            $pdo->quote($appUser),
            $pdo->quote($fromHost)
        ));
    }

    $pdo->exec('FLUSH PRIVILEGES');

    echo "Usuario '{$appUser}' listo con permisos SELECT/INSERT/UPDATE/DELETE sobre '{$database}'.\n";

    // Comprobación real: conectar con las credenciales de la aplicación.
    $check = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database),
        $appUser,
        $appPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $count = $check->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "Conexión verificada con el usuario de la aplicación (usuarios en BD: {$count}).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Si root tiene contraseña, pásala con --admin-password=TU_CLAVE\n");
    exit(1);
}
