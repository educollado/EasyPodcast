<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

test('migration_v21 aísla el contenido legado bajo el podcast existente', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT, description TEXT, link TEXT)");
    $pdo->exec("CREATE TABLE episodes (
        id INTEGER PRIMARY KEY, guid TEXT UNIQUE, title TEXT, content TEXT, short_description TEXT, link TEXT,
        pub_date TEXT, audio_url TEXT, audio_mime_type TEXT, audio_size_bytes INTEGER, duration TEXT,
        explicit INTEGER, season_number INTEGER, episode_number INTEGER, episode_type TEXT, image_url TEXT,
        author TEXT, status TEXT DEFAULT 'draft', created_at TEXT, updated_at TEXT
    )");
    $pdo->exec("CREATE TABLE pages (
        id INTEGER PRIMARY KEY, title TEXT, slug TEXT, full_path TEXT UNIQUE, content TEXT DEFAULT '',
        parent_id INTEGER, sort_order INTEGER DEFAULT 0, status TEXT DEFAULT 'draft', created_at TEXT, updated_at TEXT
    )");
    $pdo->exec("CREATE TABLE social (id INTEGER PRIMARY KEY, blog TEXT DEFAULT '', linkedin TEXT DEFAULT '', mastodon TEXT DEFAULT '', x TEXT DEFAULT '', pixelfed TEXT DEFAULT '', instagram TEXT DEFAULT '', youtube TEXT DEFAULT '', github TEXT DEFAULT '', bluesky TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, token TEXT, token_hash TEXT, token_suffix TEXT, scope TEXT, name TEXT, user_id INTEGER, expires_at TEXT, last_used_at TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE estadisticas (id INTEGER PRIMARY KEY, episode_id INTEGER, episode_guid TEXT, episode_title TEXT, ip_address TEXT, user_agent TEXT, referer TEXT, action_type TEXT, download_date TEXT)");
    $pdo->exec("CREATE TABLE estadisticas_mensuales (id INTEGER PRIMARY KEY, episode_id INTEGER, episode_title TEXT, anio INTEGER, mes INTEGER, descargas INTEGER, UNIQUE(episode_id, anio, mes))");
    $pdo->exec("CREATE TABLE estadisticas_anuales (id INTEGER PRIMARY KEY, episode_id INTEGER, episode_title TEXT, anio INTEGER, descargas INTEGER, UNIQUE(episode_id, anio))");
    $pdo->exec("INSERT INTO podcast (id, title, description, link) VALUES (7, 'Legado', '', 'https://example.com')");
    $pdo->exec("INSERT INTO episodes (id, guid, title, content, audio_url, audio_mime_type, audio_size_bytes, status) VALUES (3, 'legacy-guid', 'Uno', '', '/a.mp3', 'audio/mpeg', 1, 'published')");
    $pdo->exec("INSERT INTO pages (id, title, slug, full_path) VALUES (4, 'Acerca', 'acerca', 'acerca')");
    $pdo->exec("INSERT INTO social (id) VALUES (1)");
    $pdo->exec("INSERT INTO api_tokens (id, token_hash, user_id) VALUES (2, 'hash', 1)");
    $pdo->exec("INSERT INTO estadisticas (id, episode_id, episode_guid, episode_title, ip_address, action_type) VALUES (5, 3, 'legacy-guid', 'Uno', '127.0.0.1', 'download')");

    migration_v21($pdo);

    assert_eq(7, (int) $pdo->query('SELECT podcast_id FROM episodes WHERE id = 3')->fetchColumn());
    assert_eq(7, (int) $pdo->query('SELECT podcast_id FROM pages WHERE id = 4')->fetchColumn());
    assert_eq(7, (int) $pdo->query('SELECT podcast_id FROM social WHERE id = 1')->fetchColumn());
    assert_eq(7, (int) $pdo->query('SELECT podcast_id FROM api_tokens WHERE id = 2')->fetchColumn());
    assert_eq(7, (int) $pdo->query('SELECT podcast_id FROM estadisticas WHERE id = 5')->fetchColumn());
    assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM app_settings WHERE id = 1')->fetchColumn());
});
