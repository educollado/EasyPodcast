<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/episode_query.php';

function episodeQueryTestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function createEpisodeQueryTestDb(): string
{
    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-episode-query-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de EpisodeQueryTest');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec(
        "CREATE TABLE podcast (
            id INTEGER PRIMARY KEY,
            title TEXT,
            link TEXT
        )"
    );
    $pdo->exec(
        "CREATE TABLE episodes (
            id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL DEFAULT 1,
            guid TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            link TEXT,
            pub_date TEXT,
            audio_url TEXT NOT NULL,
            audio_mime_type TEXT NOT NULL,
            audio_size_bytes INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            updated_at TEXT
        )"
    );
    $pdo->exec("INSERT INTO podcast (id, title, link) VALUES (1, 'Podcast test', 'https://example.com')");

    return $dbPath;
}

// =============================================================================
// extractEpisodeRouteFromLink
// =============================================================================

test('extractEpisodeRouteFromLink: extrae year/month/slug de ruta relativa', function () {
    $route = extractEpisodeRouteFromLink('/2026/03/mi-episodio');
    assert_eq(['year' => '2026', 'month' => '03', 'slug' => 'mi-episodio'], $route);
});

test('extractEpisodeRouteFromLink: extrae year/month/slug de URL absoluta', function () {
    $route = extractEpisodeRouteFromLink('https://example.com/2026/11/otro-episodio/');
    assert_eq(['year' => '2026', 'month' => '11', 'slug' => 'otro-episodio'], $route);
});

test('extractEpisodeRouteFromLink: enlace sin patrón válido devuelve null', function () {
    assert_null(extractEpisodeRouteFromLink('/episodios/mi-episodio'));
});

// =============================================================================
// episodeMatchesRoute
// =============================================================================

test('episodeMatchesRoute: usa el link como fuente de verdad aunque pub_date sea null', function () {
    $row = [
        'title' => 'Título no relevante',
        'link' => '/2026/03/mi-borrador',
        'pub_date' => null,
    ];
    assert_true(episodeMatchesRoute($row, '2026', '03', 'mi-borrador'));
});

test('episodeMatchesRoute: si link válido no coincide, devuelve false', function () {
    $row = [
        'title' => 'Mi borrador',
        'link' => '/2025/12/mi-borrador',
        'pub_date' => '2026-03-10 10:00:00',
    ];
    assert_true(!episodeMatchesRoute($row, '2026', '03', 'mi-borrador'));
});

test('episodeMatchesRoute: fallback por pub_date+título cuando no hay link válido', function () {
    $row = [
        'title' => 'Mi Episodio',
        'link' => '',
        'pub_date' => '2026-03-10 10:00:00',
    ];
    assert_true(episodeMatchesRoute($row, '2026', '03', 'mi-episodio'));
});

test('episodeMatchesRoute: sin link válido y sin pub_date parseable devuelve false', function () {
    $row = [
        'title' => 'Mi Episodio',
        'link' => '',
        'pub_date' => 'fecha-invalida',
    ];
    assert_true(!episodeMatchesRoute($row, '2026', '03', 'mi-episodio'));
});

// =============================================================================
// loadEpisodeData
// =============================================================================

test('loadEpisodeData: admin puede previsualizar episodios scheduled', function () {
    if (!episodeQueryTestHasSqliteDriver()) {
        return;
    }

    $dbPath = createEpisodeQueryTestDb();

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "INSERT INTO episodes
             (guid, title, content, link, pub_date, audio_url, audio_mime_type, audio_size_bytes, status)
             VALUES (:guid, :title, :content, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes, :status)"
        );
        $stmt->execute([
            ':guid' => 'ep-scheduled-preview',
            ':title' => 'Capítulo programado',
            ':content' => 'Contenido',
            ':link' => '/2026/05/capitulo-programado',
            ':pub_date' => '2999-05-04 10:00:00',
            ':audio_url' => 'https://example.com/audio.mp3',
            ':audio_mime_type' => 'audio/mpeg',
            ':audio_size_bytes' => 1234,
            ':status' => 'scheduled',
        ]);

        $result = loadEpisodeData($dbPath, '2026', '05', 'capitulo-programado', true);

        assert_eq(200, $result['httpStatus']);
        assert_eq('scheduled', $result['episode']['status'] ?? null);
    } finally {
        @unlink($dbPath);
    }
});

test('loadEpisodeData: público no puede acceder a episodios scheduled', function () {
    if (!episodeQueryTestHasSqliteDriver()) {
        return;
    }

    $dbPath = createEpisodeQueryTestDb();

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "INSERT INTO episodes
             (guid, title, content, link, pub_date, audio_url, audio_mime_type, audio_size_bytes, status)
             VALUES (:guid, :title, :content, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes, :status)"
        );
        $stmt->execute([
            ':guid' => 'ep-scheduled-public',
            ':title' => 'Capítulo programado',
            ':content' => 'Contenido',
            ':link' => '/2026/05/capitulo-programado',
            ':pub_date' => '2999-05-04 10:00:00',
            ':audio_url' => 'https://example.com/audio.mp3',
            ':audio_mime_type' => 'audio/mpeg',
            ':audio_size_bytes' => 1234,
            ':status' => 'scheduled',
        ]);

        $result = loadEpisodeData($dbPath, '2026', '05', 'capitulo-programado', false);

        assert_eq(404, $result['httpStatus']);
        assert_null($result['episode']);
    } finally {
        @unlink($dbPath);
    }
});

test('loadEpisodeData: publica automáticamente episodios scheduled vencidos', function () {
    if (!episodeQueryTestHasSqliteDriver()) {
        return;
    }

    $dbPath = createEpisodeQueryTestDb();

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "INSERT INTO episodes
             (guid, title, content, link, pub_date, audio_url, audio_mime_type, audio_size_bytes, status)
             VALUES (:guid, :title, :content, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes, :status)"
        );
        $stmt->execute([
            ':guid' => 'ep-scheduled-due',
            ':title' => 'Capítulo vencido',
            ':content' => 'Contenido',
            ':link' => '/2000/05/capitulo-vencido',
            ':pub_date' => '2000-05-04 10:00:00',
            ':audio_url' => 'https://example.com/audio.mp3',
            ':audio_mime_type' => 'audio/mpeg',
            ':audio_size_bytes' => 1234,
            ':status' => 'scheduled',
        ]);

        $result = loadEpisodeData($dbPath, '2000', '05', 'capitulo-vencido', false);

        assert_eq(200, $result['httpStatus']);
        assert_eq('published', $result['episode']['status'] ?? null);
        assert_eq('published', $pdo->query("SELECT status FROM episodes WHERE guid = 'ep-scheduled-due'")->fetchColumn());
    } finally {
        @unlink($dbPath);
    }
});
