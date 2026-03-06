<?php
/**
 * Inicializa la base de datos SQLite aplicando schema.sql si no existe.
 * Se ejecuta desde entrypoint.sh antes de arrancar Apache.
 */
$dbPath = $argv[1] ?? '/var/www/html/data/podcast.sqlite';

if (file_exists($dbPath)) {
    exit(0);
}

$schema = file_get_contents(__DIR__ . '/../schema.sql');
if ($schema === false) {
    fwrite(STDERR, "[init_db] No se encontró schema.sql\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec($schema);

echo "[init_db] Base de datos inicializada en $dbPath\n";
