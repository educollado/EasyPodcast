<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v23 añade textos y tema propios para el resumen multipodcast', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, admin_theme TEXT)');
    $pdo->exec("INSERT INTO podcast VALUES (1, 'monokai')");
    $pdo->exec('CREATE TABLE app_settings (id INTEGER PRIMARY KEY, summary_hero_image_url TEXT)');
    $pdo->exec("INSERT INTO app_settings (id, summary_hero_image_url) VALUES (1, 'https://example.com/hero.jpg')");

    migration_v23($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(app_settings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['summary_title', 'summary_subtitle', 'summary_theme'] as $column) {
        assert_true(in_array($column, $columns, true));
    }
    assert_eq('monokai', $pdo->query('SELECT summary_theme FROM app_settings')->fetchColumn());
    assert_eq('https://example.com/hero.jpg', $pdo->query('SELECT summary_hero_image_url FROM app_settings')->fetchColumn());

    migration_v23($pdo);
    $columnsAfterRetry = array_column($pdo->query('PRAGMA table_info(app_settings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['summary_title', 'summary_subtitle', 'summary_theme'] as $column) {
        assert_eq(1, count(array_filter($columnsAfterRetry, static fn (string $existing): bool => $existing === $column)));
    }
});
