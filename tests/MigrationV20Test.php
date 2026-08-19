<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v20: añade el estado de comprobación diaria de actualizaciones', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec("INSERT INTO podcast (id, title) VALUES (1, 'Podcast')");

    migration_v20($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_true(in_array('last_update_check_date', $columns, true));
    assert_true(in_array('latest_version_checked', $columns, true));
    assert_null($pdo->query('SELECT last_update_check_date FROM podcast WHERE id = 1')->fetchColumn());
    assert_null($pdo->query('SELECT latest_version_checked FROM podcast WHERE id = 1')->fetchColumn());

    migration_v20($pdo);
    $columnsAfterRetry = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_eq(1, count(array_filter($columnsAfterRetry, static fn (string $column): bool => $column === 'last_update_check_date')));
    assert_eq(1, count(array_filter($columnsAfterRetry, static fn (string $column): bool => $column === 'latest_version_checked')));
});
