<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/scheduler.php';

function schedulerTestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function createSchedulerTestDb(): string
{
    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-scheduler-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de SchedulerTest');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE episodes (
            id INTEGER PRIMARY KEY,
            guid TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            pub_date TEXT,
            audio_url TEXT NOT NULL,
            audio_mime_type TEXT NOT NULL,
            audio_size_bytes INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            updated_at TEXT
        )"
    );

    return $dbPath;
}

test('publishScheduledEpisodesInDatabase: usa la hora local de PHP para publicar', function () {
    if (!schedulerTestHasSqliteDriver()) {
        return;
    }

    $previousTimezone = date_default_timezone_get();
    $dbPath = createSchedulerTestDb();

    try {
        date_default_timezone_set('Europe/Madrid');

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $insert = $pdo->prepare(
            "INSERT INTO episodes
             (guid, title, content, pub_date, audio_url, audio_mime_type, audio_size_bytes, status, updated_at)
             VALUES (:guid, :title, :content, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes, :status, :updated_at)"
        );

        $insert->execute([
            ':guid' => 'ep-due-local-time',
            ':title' => 'Ya debería estar publicado',
            ':content' => 'Contenido',
            ':pub_date' => date('Y-m-d H:i:s', time() - 1800),
            ':audio_url' => 'https://example.com/due.mp3',
            ':audio_mime_type' => 'audio/mpeg',
            ':audio_size_bytes' => 1024,
            ':status' => 'scheduled',
            ':updated_at' => '2026-05-04 00:00:00',
        ]);
        $insert->execute([
            ':guid' => 'ep-future-local-time',
            ':title' => 'Todavía no',
            ':content' => 'Contenido',
            ':pub_date' => date('Y-m-d H:i:s', time() + 1800),
            ':audio_url' => 'https://example.com/future.mp3',
            ':audio_mime_type' => 'audio/mpeg',
            ':audio_size_bytes' => 1024,
            ':status' => 'scheduled',
            ':updated_at' => '2026-05-04 00:00:00',
        ]);

        $publishedCount = publishScheduledEpisodesInDatabase($pdo);

        assert_eq(1, $publishedCount);

        $rows = $pdo->query("SELECT guid, status FROM episodes ORDER BY guid ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_eq('published', $rows['ep-due-local-time'] ?? null);
        assert_eq('scheduled', $rows['ep-future-local-time'] ?? null);
    } finally {
        date_default_timezone_set($previousTimezone);
        @unlink($dbPath);
    }
});
