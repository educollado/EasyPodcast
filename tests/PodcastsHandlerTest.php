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
    $pdo->exec("INSERT INTO podcast (id, slug) VALUES (1, 'principal'), (2, 'secundario')");
    $pdo->exec(
        "CREATE TABLE app_settings (
          id INTEGER PRIMARY KEY,
          multipodcast_enabled INTEGER NOT NULL DEFAULT 0,
          homepage_podcast_id INTEGER,
          summary_hero_image_url TEXT,
          summary_title TEXT,
          summary_subtitle TEXT,
          summary_theme TEXT NOT NULL DEFAULT 'easypodcast',
          primary_podcast_id INTEGER
        )"
    );
    $pdo->exec("INSERT INTO app_settings VALUES (1, 1, NULL, 'https://example.com/old.jpg', 'Título anterior', 'Subtítulo anterior', 'corporate', 1)");
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
        'summary_title' => 'Todos mis programas',
        'summary_subtitle' => 'Escucha el que prefieras',
        'summary_theme' => 'monokai',
    ];
    $_FILES = [];

    try {
        saveMultipodcastSettings($pdo, sys_get_temp_dir() . '/unused.sqlite', sys_get_temp_dir());
    } finally {
        $_POST = $oldPost;
        $_FILES = $oldFiles;
    }

    assert_eq('https://example.com/summary.jpg', $pdo->query('SELECT summary_hero_image_url FROM app_settings')->fetchColumn());
    assert_eq('Todos mis programas', $pdo->query('SELECT summary_title FROM app_settings')->fetchColumn());
    assert_eq('Escucha el que prefieras', $pdo->query('SELECT summary_subtitle FROM app_settings')->fetchColumn());
    assert_eq('monokai', $pdo->query('SELECT summary_theme FROM app_settings')->fetchColumn());
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
        saveMultipodcastSettings($pdo, sys_get_temp_dir() . '/unused.sqlite', sys_get_temp_dir());
    } finally {
        $_POST = $oldPost;
        $_FILES = $oldFiles;
    }

    assert_eq('https://example.com/old.jpg', $pdo->query('SELECT summary_hero_image_url FROM app_settings')->fetchColumn());
    assert_eq('Título anterior', $pdo->query('SELECT summary_title FROM app_settings')->fetchColumn());
    assert_eq('Subtítulo anterior', $pdo->query('SELECT summary_subtitle FROM app_settings')->fetchColumn());
    assert_eq('corporate', $pdo->query('SELECT summary_theme FROM app_settings')->fetchColumn());
    assert_eq(1, (int) $pdo->query('SELECT homepage_podcast_id FROM app_settings')->fetchColumn());
});

test('setPrimaryPodcast cambia el podcast que queda visible en modo sencillo', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $pdo = podcastsHandlerSettingsDatabase();

    setPrimaryPodcast($pdo, 2);

    assert_eq(2, (int) $pdo->query('SELECT primary_podcast_id FROM app_settings')->fetchColumn());
    assert_eq('secundario', primaryPodcast($pdo)['slug'] ?? null);
});

function podcastsHandlerIntegrationDatabase(string $root): array
{
    mkdir($root, 0775, true);
    mkdir($root . '/audios', 0775, true);
    mkdir($root . '/images', 0775, true);
    $dbPath = $root . '/podcast.sqlite';
    $pdo = openPodcastDatabase($dbPath);
    $schema = file_get_contents(__DIR__ . '/../schema.sql');
    if ($schema === false) {
        throw new RuntimeException('No se pudo leer schema.sql');
    }
    $pdo->exec($schema);
    return [$pdo, $dbPath];
}

test('activar Multipodcast asigna el directorio sin mover medios ni cambiar sus URLs', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $root = sys_get_temp_dir() . '/easypodcast-enable-' . bin2hex(random_bytes(4));
    [$pdo, $dbPath] = podcastsHandlerIntegrationDatabase($root);
    $pdo->exec("INSERT INTO podcast (id, title, description, link, image_url) VALUES (1, 'Áratos', '', 'https://example.com', '/images/cover.jpg')");
    $pdo->exec("UPDATE app_settings SET primary_podcast_id = 1");
    $pdo->exec("UPDATE app_settings SET summary_hero_image_url = '/images/summary.jpg'");
    file_put_contents($root . '/images/cover.jpg', 'image');
    file_put_contents($root . '/images/summary.jpg', 'summary');
    file_put_contents($root . '/audios/episode.mp3', 'audio');
    $oldPost = $_POST;
    $oldFiles = $_FILES;
    $_POST = ['multipodcast_enabled' => '1', 'conversion_slug' => 'áratos', 'homepage_podcast_id' => '1'];
    $_FILES = [];
    try {
        saveMultipodcastSettings($pdo, $dbPath, $root);
        assert_true(is_file($root . '/images/cover.jpg'));
        assert_true(is_file($root . '/images/summary.jpg'));
        assert_true(is_file($root . '/audios/episode.mp3'));
        assert_eq('aratos', $pdo->query('SELECT slug FROM podcast WHERE id = 1')->fetchColumn());
        assert_eq('/images/cover.jpg', $pdo->query('SELECT image_url FROM podcast WHERE id = 1')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT multipodcast_enabled FROM app_settings')->fetchColumn());
    } finally {
        $_POST = $oldPost;
        $_FILES = $oldFiles;
        removePodcastDirectory($root);
    }
});

test('desactivar Multipodcast respalda y borra secundarios sin cambiar los medios del principal', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !class_exists('ZipArchive') || !class_exists('SQLite3')) {
        return;
    }
    $root = sys_get_temp_dir() . '/easypodcast-disable-' . bin2hex(random_bytes(4));
    [$pdo, $dbPath] = podcastsHandlerIntegrationDatabase($root);
    $pdo->exec("INSERT INTO podcast (id, title, description, link, image_url, slug) VALUES (1, 'Principal', '', 'https://example.com/principal', '/images/principal.jpg', 'principal')");
    $pdo->exec("INSERT INTO podcast (id, title, description, link, image_url, slug) VALUES (2, 'Secundario', '', 'https://example.com/secundario', '/images/secundario.jpg', 'secundario')");
    $pdo->exec("INSERT INTO episodes (id, podcast_id, guid, title, content, audio_url, audio_mime_type, audio_size_bytes, image_url) VALUES (1, 1, 'p1', 'Principal', '', '/audios/principal.mp3', 'audio/mpeg', 9, '/images/shared.jpg')");
    $pdo->exec("INSERT INTO episodes (id, podcast_id, guid, title, content, audio_url, audio_mime_type, audio_size_bytes, image_url) VALUES (2, 2, 'p2', 'Secundario', '', '/audios/secundario.mp3', 'audio/mpeg', 10, '/images/shared.jpg')");
    $pdo->exec("UPDATE app_settings SET multipodcast_enabled = 1, primary_podcast_id = 1, homepage_podcast_id = 1");
    file_put_contents($root . '/images/principal.jpg', 'principal');
    file_put_contents($root . '/images/secundario.jpg', 'secondary');
    file_put_contents($root . '/images/shared.jpg', 'shared');
    file_put_contents($root . '/audios/principal.mp3', 'principal');
    file_put_contents($root . '/audios/secundario.mp3', 'secondary');
    $oldPost = $_POST;
    $oldFiles = $_FILES;
    $_POST = [
        'homepage_podcast_id' => '1',
        'confirm_disable' => '1',
        'disable_confirm_title' => 'Principal',
    ];
    $_FILES = [];
    try {
        $result = saveMultipodcastSettings($pdo, $dbPath, $root);
        assert_eq(1, count($result['backup_files']));
        assert_true(is_file($root . '/backups/' . $result['backup_files'][0]));
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM podcast')->fetchColumn());
        assert_null($pdo->query('SELECT slug FROM podcast WHERE id = 1')->fetchColumn());
        assert_eq('/images/principal.jpg', $pdo->query('SELECT image_url FROM podcast WHERE id = 1')->fetchColumn());
        assert_true(is_file($root . '/images/principal.jpg'));
        assert_true(is_file($root . '/images/shared.jpg'));
        assert_true(is_file($root . '/audios/principal.mp3'));
        assert_true(!is_file($root . '/images/secundario.jpg'));
        assert_true(!is_file($root . '/audios/secundario.mp3'));
        assert_eq(0, (int) $pdo->query('SELECT multipodcast_enabled FROM app_settings')->fetchColumn());
    } finally {
        $_POST = $oldPost;
        $_FILES = $oldFiles;
        removePodcastDirectory($root);
    }
});
