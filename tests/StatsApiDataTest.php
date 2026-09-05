<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/stats_handler.php';
require_once __DIR__ . '/../lib/stats_downloads_handler.php';
require_once __DIR__ . '/../lib/i18n.php';

i18n_load('es_ES');

function statsApiTestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function statsApiTestCreateDatabase(): array
{
    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-stats-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de test');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE podcast (
            id INTEGER PRIMARY KEY,
            cache_enabled INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE episodes (
            id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL DEFAULT 1,
            guid TEXT NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            audio_url TEXT NOT NULL,
            audio_mime_type TEXT NOT NULL,
            audio_size_bytes INTEGER NOT NULL,
            pub_date TEXT,
            status TEXT NOT NULL DEFAULT "draft"
        )'
    );

    $pdo->exec(
        'CREATE TABLE estadisticas (
            id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL DEFAULT 1,
            episode_id INTEGER NOT NULL,
            episode_guid TEXT NOT NULL,
            episode_title TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            user_agent TEXT,
            referer TEXT,
            action_type TEXT NOT NULL DEFAULT "download",
            download_date TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE estadisticas_mensuales (
            id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL DEFAULT 1,
            episode_id INTEGER NOT NULL,
            episode_title TEXT NOT NULL,
            anio INTEGER NOT NULL,
            mes INTEGER NOT NULL,
            descargas INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE estadisticas_anuales (
            id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL DEFAULT 1,
            episode_id INTEGER NOT NULL,
            episode_title TEXT NOT NULL,
            anio INTEGER NOT NULL,
            descargas INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec('INSERT INTO podcast (id, cache_enabled) VALUES (1, 1)');

    return ['pdo' => $pdo, 'dbPath' => $dbPath];
}

function statsApiTestCreateCacheDir(): string
{
    $dir = sys_get_temp_dir() . '/easypodcast-cache-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio de caché temporal');
    }

    return $dir;
}

function statsApiTestRemoveCacheDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $entries = scandir($dir);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            @rmdir($path);
        }
    }

    @rmdir($dir);
}

test('getStatsOverview y getCacheStatsData: resumen general y caché', function () {
    if (!statsApiTestHasSqliteDriver()) {
        return;
    }

    ['pdo' => $pdo, 'dbPath' => $dbPath] = statsApiTestCreateDatabase();
    $cacheDir = statsApiTestCreateCacheDir();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO episodes (
                id, guid, title, content, audio_url, audio_mime_type,
                audio_size_bytes, pub_date, status
            ) VALUES (
                :id, :guid, :title, :content, :audio_url, :audio_mime_type,
                :audio_size_bytes, :pub_date, :status
            )'
        );

        $episodes = [
            [
                'id' => 1,
                'guid' => 'guid-1',
                'title' => 'Episodio 1',
                'content' => 'Contenido 1',
                'audio_url' => '/audios/ep1.mp3',
                'audio_mime_type' => 'audio/mpeg',
                'audio_size_bytes' => 1024,
                'pub_date' => '2026-04-01 10:00:00',
                'status' => 'published',
            ],
            [
                'id' => 2,
                'guid' => 'guid-2',
                'title' => 'Borrador',
                'content' => 'Contenido 2',
                'audio_url' => '/audios/ep2.mp3',
                'audio_mime_type' => 'audio/mpeg',
                'audio_size_bytes' => 2048,
                'pub_date' => null,
                'status' => 'draft',
            ],
            [
                'id' => 3,
                'guid' => 'guid-3',
                'title' => 'Episodio más reciente',
                'content' => 'Contenido 3',
                'audio_url' => '/audios/ep3.mp3',
                'audio_mime_type' => 'audio/mpeg',
                'audio_size_bytes' => 4096,
                'pub_date' => '2026-04-20 09:30:00',
                'status' => 'published',
            ],
        ];

        foreach ($episodes as $episode) {
            $stmt->execute($episode);
        }

        file_put_contents($cacheDir . '/home.cache', 'abc');
        file_put_contents($cacheDir . '/feed.cache', '12345');
        mkdir($cacheDir . '/subdir');

        $overview = getStatsOverview($pdo);
        $cache = getCacheStatsData($dbPath, $cacheDir);

        assert_eq(2, $overview['published']);
        assert_eq(1, $overview['drafts']);
        assert_eq(3, $overview['total']);
        assert_eq('Episodio más reciente', $overview['lastTitle']);
        assert_eq('2026-04-20 09:30:00', $overview['lastPubDate']);
        assert_eq(7168, $overview['audioSizeBytes']);

        assert_true($cache['cacheEnabled']);
        assert_eq(2, $cache['cacheFiles']);
        assert_eq(8, $cache['cacheSizeBytes']);
    } finally {
        $pdo = null;
        @unlink($dbPath);
        statsApiTestRemoveCacheDir($cacheDir);
    }
});

test('getDownloadsStatsData: devuelve todas las colecciones y respeta el filtro anual', function () {
    if (!statsApiTestHasSqliteDriver()) {
        return;
    }

    ['pdo' => $pdo, 'dbPath' => $dbPath] = statsApiTestCreateDatabase();

    try {
        $episodeStmt = $pdo->prepare(
            'INSERT INTO episodes (
                id, guid, title, content, audio_url, audio_mime_type,
                audio_size_bytes, pub_date, status
            ) VALUES (
                :id, :guid, :title, :content, :audio_url, :audio_mime_type,
                :audio_size_bytes, :pub_date, :status
            )'
        );

        foreach ([
            [
                'id' => 1,
                'guid' => 'guid-1',
                'title' => 'Episodio 1',
                'content' => 'Contenido 1',
                'audio_url' => '/audios/ep1.mp3',
                'audio_mime_type' => 'audio/mpeg',
                'audio_size_bytes' => 1024,
                'pub_date' => '2026-04-01 10:00:00',
                'status' => 'published',
            ],
            [
                'id' => 2,
                'guid' => 'guid-2',
                'title' => 'Sin descargas',
                'content' => 'Contenido 2',
                'audio_url' => '/audios/ep2.mp3',
                'audio_mime_type' => 'audio/mpeg',
                'audio_size_bytes' => 2048,
                'pub_date' => null,
                'status' => 'draft',
            ],
            [
                'id' => 3,
                'guid' => 'guid-3',
                'title' => 'Episodio 3',
                'content' => 'Contenido 3',
                'audio_url' => '/audios/ep3.mp3',
                'audio_mime_type' => 'audio/mpeg',
                'audio_size_bytes' => 4096,
                'pub_date' => '2026-04-20 09:30:00',
                'status' => 'published',
            ],
        ] as $episode) {
            $episodeStmt->execute($episode);
        }

        $dailyStmt = $pdo->prepare(
            'INSERT INTO estadisticas (
                id, episode_id, episode_guid, episode_title, ip_address,
                user_agent, referer, action_type, download_date
            ) VALUES (
                :id, :episode_id, :episode_guid, :episode_title, :ip_address,
                :user_agent, :referer, :action_type, :download_date
            )'
        );

        foreach ([
            [
                'id' => 1,
                'episode_id' => 1,
                'episode_guid' => 'guid-1',
                'episode_title' => 'Episodio 1',
                'ip_address' => '10.0.0.1',
                'user_agent' => 'UA 1',
                'referer' => 'https://example.com/1',
                'action_type' => 'download',
                'download_date' => '2026-04-21 10:00:00',
            ],
            [
                'id' => 2,
                'episode_id' => 3,
                'episode_guid' => 'guid-3',
                'episode_title' => 'Episodio 3',
                'ip_address' => '10.0.0.2',
                'user_agent' => 'UA 2',
                'referer' => 'https://example.com/3',
                'action_type' => 'feed',
                'download_date' => '2026-04-22 11:00:00',
            ],
        ] as $row) {
            $dailyStmt->execute($row);
        }

        $monthlyStmt = $pdo->prepare(
            'INSERT INTO estadisticas_mensuales (
                id, episode_id, episode_title, anio, mes, descargas
            ) VALUES (
                :id, :episode_id, :episode_title, :anio, :mes, :descargas
            )'
        );

        foreach ([
            ['id' => 1, 'episode_id' => 1, 'episode_title' => 'Episodio 1', 'anio' => 2026, 'mes' => 4, 'descargas' => 5],
            ['id' => 2, 'episode_id' => 1, 'episode_title' => 'Episodio 1', 'anio' => 2025, 'mes' => 12, 'descargas' => 3],
            ['id' => 3, 'episode_id' => 3, 'episode_title' => 'Episodio 3', 'anio' => 2026, 'mes' => 4, 'descargas' => 2],
        ] as $row) {
            $monthlyStmt->execute($row);
        }

        $yearlyStmt = $pdo->prepare(
            'INSERT INTO estadisticas_anuales (
                id, episode_id, episode_title, anio, descargas
            ) VALUES (
                :id, :episode_id, :episode_title, :anio, :descargas
            )'
        );

        foreach ([
            ['id' => 1, 'episode_id' => 1, 'episode_title' => 'Episodio 1', 'anio' => 2026, 'descargas' => 5],
            ['id' => 2, 'episode_id' => 1, 'episode_title' => 'Episodio 1', 'anio' => 2025, 'descargas' => 3],
            ['id' => 3, 'episode_id' => 3, 'episode_title' => 'Episodio 3', 'anio' => 2026, 'descargas' => 2],
        ] as $row) {
            $yearlyStmt->execute($row);
        }

        $downloads = getDownloadsStatsData($pdo, 2026);

        assert_eq(2026, $downloads['filter_year']);
        assert_eq([2026, 2025], $downloads['available_years']);

        assert_eq(2, $downloads['daily']['total']);
        assert_eq('Episodio 3', $downloads['daily']['items'][0]['episode_title']);
        assert_eq('Feed', $downloads['daily']['items'][0]['action_type_label']);
        assert_eq('22/04/2026 11:00:00', $downloads['daily']['items'][0]['display_date']);

        assert_eq(2, $downloads['monthly']['total']);
        assert_eq('Abr 2026', $downloads['monthly']['items'][0]['period_label']);

        assert_eq(3, $downloads['yearly']['total']);

        assert_eq(2, $downloads['summary']['total']);
        assert_eq(1, $downloads['summary']['items'][0]['episode_id']);
        assert_eq(8, (int) $downloads['summary']['items'][0]['total_downloads']);
        assert_eq('Episodio 1', $downloads['summary']['items'][0]['episode_title']);
    } finally {
        $pdo = null;
        @unlink($dbPath);
    }
});
