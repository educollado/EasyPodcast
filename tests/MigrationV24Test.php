<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v24 inicializa el podcast principal con el primero existente', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec("INSERT INTO podcast VALUES (7, 'Primero'), (12, 'Segundo')");
    $pdo->exec('CREATE TABLE app_settings (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO app_settings VALUES (1)');

    migration_v24($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(app_settings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_true(in_array('primary_podcast_id', $columns, true));
    assert_eq(7, (int) $pdo->query('SELECT primary_podcast_id FROM app_settings')->fetchColumn());

    migration_v24($pdo);
    $columnsAfterRetry = array_column($pdo->query('PRAGMA table_info(app_settings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_eq(1, count(array_filter($columnsAfterRetry, static fn (string $column): bool => $column === 'primary_podcast_id')));
});
