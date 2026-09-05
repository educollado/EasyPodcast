<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/home_query.php';

function homeQueryTestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function createHomeQueryTestDb(): string
{
    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-home-query-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de HomeQueryTest');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec(
        "CREATE TABLE podcast (
            id INTEGER PRIMARY KEY,
            title TEXT,
            link TEXT,
            home_items_per_page INTEGER
        )"
    );
    $pdo->exec(
        "CREATE TABLE episodes (
            id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL DEFAULT 1,
            guid TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            short_description TEXT,
            link TEXT,
            pub_date TEXT,
            audio_url TEXT NOT NULL,
            audio_mime_type TEXT NOT NULL,
            audio_size_bytes INTEGER NOT NULL,
            duration TEXT,
            image_url TEXT,
            status TEXT NOT NULL DEFAULT 'draft',
            updated_at TEXT
        )"
    );
    $pdo->exec("INSERT INTO podcast (id, title, link, home_items_per_page) VALUES (1, 'Podcast test', 'https://example.com', 20)");

    return $dbPath;
}

test('loadHomeData: publica automáticamente episodios scheduled vencidos', function () {
    if (!homeQueryTestHasSqliteDriver()) {
        return;
    }

    $dbPath = createHomeQueryTestDb();

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare(
            "INSERT INTO episodes
             (guid, title, content, short_description, link, pub_date, audio_url, audio_mime_type, audio_size_bytes, duration, image_url, status, updated_at)
             VALUES (:guid, :title, :content, :short_description, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes, :duration, :image_url, :status, :updated_at)"
        );
        $stmt->execute([
            ':guid' => 'ep-home-due',
            ':title' => 'Capítulo programado vencido',
            ':content' => 'Contenido',
            ':short_description' => 'Resumen',
            ':link' => '/2000/05/capitulo-programado-vencido',
            ':pub_date' => '2000-05-04 10:00:00',
            ':audio_url' => 'https://example.com/audio.mp3',
            ':audio_mime_type' => 'audio/mpeg',
            ':audio_size_bytes' => 1234,
            ':duration' => '00:01:00',
            ':image_url' => 'https://example.com/cover.jpg',
            ':status' => 'scheduled',
            ':updated_at' => '2000-05-04 09:00:00',
        ]);

        $result = loadHomeData($dbPath, 1);

        assert_eq(1, $result['totalEpisodes']);
        assert_eq('Capítulo programado vencido', $result['episodes'][0]['title'] ?? null);
        assert_eq('published', $pdo->query("SELECT status FROM episodes WHERE guid = 'ep-home-due'")->fetchColumn());
    } finally {
        @unlink($dbPath);
    }
});
