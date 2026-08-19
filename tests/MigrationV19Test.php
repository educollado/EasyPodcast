<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

function migrationV19TestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

test('migration_v19: añade hero_image_url sin alterar la imagen del podcast', function () {
    if (!migrationV19TestHasSqliteDriver()) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE podcast (
            id INTEGER PRIMARY KEY,
            image_url TEXT
        )"
    );
    $pdo->exec("INSERT INTO podcast (id, image_url) VALUES (1, '/images/cover.jpg')");

    migration_v19($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_true(in_array('hero_image_url', $columns, true));
    assert_eq('/images/cover.jpg', $pdo->query('SELECT image_url FROM podcast WHERE id = 1')->fetchColumn());
    assert_null($pdo->query('SELECT hero_image_url FROM podcast WHERE id = 1')->fetchColumn());

    // La migración debe poder repetirse sin intentar añadir de nuevo la columna.
    migration_v19($pdo);
    $columnsAfterRetry = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $heroColumns = array_filter($columnsAfterRetry, static fn (string $column): bool => $column === 'hero_image_url');
    assert_eq(1, count($heroColumns));
});
