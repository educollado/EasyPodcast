<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/podcasts_handler.php';

function podcastsHandlerSettingsDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, slug TEXT)');
    $pdo->exec("INSERT INTO podcast (id, slug) VALUES (1, 'principal')");
    $pdo->exec(
        'CREATE TABLE app_settings (
          id INTEGER PRIMARY KEY,
          multipodcast_enabled INTEGER NOT NULL DEFAULT 0,
          homepage_podcast_id INTEGER,
          summary_hero_image_url TEXT
        )'
    );
    $pdo->exec("INSERT INTO app_settings VALUES (1, 1, NULL, 'https://example.com/old.jpg')");
    return $pdo;
}

test('ajustes multipodcast guardan el hero cuando se elige la portada resumen', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $pdo = podcastsHandlerSettingsDatabase();
    $oldPost = $_POST;
    $oldFiles = $_FILES;
    $_POST = [
        'multipodcast_enabled' => '1',
        'homepage_podcast_id' => '',
        'summary_hero_image_url' => 'https://example.com/summary.jpg',
    ];
    $_FILES = [];

    try {
        saveMultipodcastSettings($pdo, sys_get_temp_dir());
    } finally {
        $_POST = $oldPost;
        $_FILES = $oldFiles;
    }

    assert_eq('https://example.com/summary.jpg', $pdo->query('SELECT summary_hero_image_url FROM app_settings')->fetchColumn());
});

test('ajustes multipodcast conservan el hero del resumen al elegir un podcast', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $pdo = podcastsHandlerSettingsDatabase();
    $oldPost = $_POST;
    $oldFiles = $_FILES;
    $_POST = [
        'multipodcast_enabled' => '1',
        'homepage_podcast_id' => '1',
        'summary_hero_image_url' => '',
    ];
    $_FILES = [];

    try {
        saveMultipodcastSettings($pdo, sys_get_temp_dir());
    } finally {
        $_POST = $oldPost;
        $_FILES = $oldFiles;
    }

    assert_eq('https://example.com/old.jpg', $pdo->query('SELECT summary_hero_image_url FROM app_settings')->fetchColumn());
    assert_eq(1, (int) $pdo->query('SELECT homepage_podcast_id FROM app_settings')->fetchColumn());
});
