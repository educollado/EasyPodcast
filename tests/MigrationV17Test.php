<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

function migrationV17TestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

test('migration_v17: añade public_theme_mode_auto a podcast si falta', function () {
    if (!migrationV17TestHasSqliteDriver()) {
        return;
    }

    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-mig17-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de migración v17');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            "CREATE TABLE podcast (
                id INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                admin_theme TEXT NOT NULL DEFAULT 'default'
            )"
        );
        $pdo->exec("INSERT INTO podcast (id, title, admin_theme) VALUES (1, 'Podcast test', 'default')");

        migration_v17($pdo);

        $columns = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(), 'name');
        assert_true(in_array('public_theme_mode_auto', $columns, true));
        assert_eq(0, (int) $pdo->query('SELECT public_theme_mode_auto FROM podcast WHERE id = 1')->fetchColumn());
    } finally {
        $pdo = null;
        @unlink($dbPath);
    }
});
