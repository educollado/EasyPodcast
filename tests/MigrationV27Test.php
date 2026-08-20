<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v27 crea el idioma Multipodcast conservando el idioma principal', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, app_language TEXT)');
    $pdo->exec("INSERT INTO podcast VALUES (1, 'es_ES'), (2, 'gl_ES')");
    $pdo->exec('CREATE TABLE app_settings (id INTEGER PRIMARY KEY, primary_podcast_id INTEGER)');
    $pdo->exec('INSERT INTO app_settings VALUES (1, 2)');

    migration_v27($pdo);

    $columns = array_column($pdo->query('PRAGMA table_info(app_settings)')->fetchAll(), 'name');
    assert_true(in_array('summary_language', $columns, true));
    assert_eq('gl_ES', $pdo->query('SELECT summary_language FROM app_settings WHERE id = 1')->fetchColumn());
});
