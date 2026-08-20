<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v22 añade el hero del resumen sin alterar la selección de portada', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE app_settings (
          id INTEGER PRIMARY KEY,
          multipodcast_enabled INTEGER NOT NULL DEFAULT 0,
          homepage_podcast_id INTEGER
        )'
    );
    $pdo->exec('INSERT INTO app_settings (id, multipodcast_enabled, homepage_podcast_id) VALUES (1, 1, 7)');

    migration_v22($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(app_settings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_true(in_array('summary_hero_image_url', $columns, true));
    assert_eq(7, (int) $pdo->query('SELECT homepage_podcast_id FROM app_settings WHERE id = 1')->fetchColumn());
    assert_null($pdo->query('SELECT summary_hero_image_url FROM app_settings WHERE id = 1')->fetchColumn());

    migration_v22($pdo);
    $columnsAfterRetry = array_column($pdo->query('PRAGMA table_info(app_settings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_eq(1, count(array_filter($columnsAfterRetry, static fn (string $column): bool => $column === 'summary_hero_image_url')));
});
