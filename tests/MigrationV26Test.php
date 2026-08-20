<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v26 conserva administradores y crea asignaciones múltiples', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { return; }
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE management (id INTEGER PRIMARY KEY, username TEXT, password TEXT)');
    $pdo->exec("INSERT INTO management VALUES (1, 'admin', 'hash')");
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO podcast VALUES (1), (2)');

    migration_v26($pdo);

    assert_eq(1, (int) $pdo->query('SELECT is_global FROM management WHERE id = 1')->fetchColumn());
    $pdo->exec('INSERT INTO management_podcasts VALUES (1, 1), (1, 2)');
    assert_eq(2, (int) $pdo->query('SELECT COUNT(*) FROM management_podcasts WHERE management_id = 1')->fetchColumn());
});
