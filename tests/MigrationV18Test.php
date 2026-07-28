<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

function migrationV18TestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

test('migration_v18: cambia únicamente el tema predeterminado histórico', function () {
    if (!migrationV18TestHasSqliteDriver()) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE podcast (
            id INTEGER PRIMARY KEY,
            admin_theme TEXT NOT NULL DEFAULT 'default'
        )"
    );
    $pdo->exec("INSERT INTO podcast (id, admin_theme) VALUES (1, 'default'), (2, 'oscuro')");

    migration_v18($pdo);

    $themes = $pdo->query('SELECT admin_theme FROM podcast ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_eq(['easypodcast', 'oscuro'], $themes);
});
