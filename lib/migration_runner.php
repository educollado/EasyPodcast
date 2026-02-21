<?php

declare(strict_types=1);

/**
 * Ejecuta las migraciones de esquema pendientes usando PRAGMA user_version.
 * Se llama una vez por request antes del primer acceso a BD.
 */
function runMigrations(string $dbPath): void
{
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

    if ($version < 1) {
        migration_v1($pdo);
        $pdo->exec('PRAGMA user_version = 1');
    }
    // Futuras: if ($version < 2) { migration_v2($pdo); $pdo->exec('PRAGMA user_version = 2'); }
}

/**
 * Migración v1: añade columnas introducidas tras el esquema inicial.
 * PRAGMA table_info devuelve [] si la tabla no existe → no se ejecuta ningún ALTER.
 */
function migration_v1(PDO $pdo): void
{
    $existing = array_column(
        $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
        'name'
    );
    $pending = [
        'rss_item_limit'       => 'ALTER TABLE podcast ADD COLUMN rss_item_limit INTEGER NOT NULL DEFAULT 0',
        'home_items_per_page'  => 'ALTER TABLE podcast ADD COLUMN home_items_per_page INTEGER NOT NULL DEFAULT 20',
        'write_audio_metadata' => 'ALTER TABLE podcast ADD COLUMN write_audio_metadata INTEGER NOT NULL DEFAULT 0',
        'cache_enabled'        => 'ALTER TABLE podcast ADD COLUMN cache_enabled INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($pending as $col => $sql) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec($sql);
        }
    }
}
