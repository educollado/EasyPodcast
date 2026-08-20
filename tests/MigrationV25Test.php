<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v25 añade la visibilidad del resumen activa por defecto', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec("INSERT INTO podcast (id, title) VALUES (1, 'Uno'), (2, 'Dos')");

    migration_v25($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_true(in_array('include_in_summary', $columns, true));
    assert_eq([1, 1], array_map('intval', $pdo->query('SELECT include_in_summary FROM podcast ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));

    migration_v25($pdo);
    $columnsAfterRetry = array_column($pdo->query('PRAGMA table_info(podcast)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_eq(1, count(array_filter($columnsAfterRetry, static fn (string $column): bool => $column === 'include_in_summary')));
});
