<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/sitemap_builder.php';
require_once __DIR__ . '/cache_service.php';

/**
 * Devuelve el instante de corte del scheduler en el mismo formato local que usa pub_date.
 *
 * La publicación programada guarda las fechas con la zona horaria actual de PHP,
 * así que aquí debemos comparar contra esa misma referencia y no contra el UTC de SQLite.
 */
function scheduledPublicationCutoff(?DateTimeInterface $now = null): string
{
    $now ??= new DateTimeImmutable('now');
    return $now->format('Y-m-d H:i:s');
}

/**
 * Publica en BD los episodios programados cuya fecha ya ha llegado.
 *
 * Devuelve cuántos episodios han pasado a published. No regenera feed/sitemap ni limpia caché;
 * esos efectos secundarios se ejecutan fuera para que esta función sea testeable.
 */
function publishScheduledEpisodesInDatabase(PDO $pdo, ?string $cutoff = null): int
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cutoff ??= scheduledPublicationCutoff();

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM episodes
         WHERE status = 'scheduled' AND datetime(pub_date) <= datetime(:cutoff)"
    );
    $countStmt->execute([':cutoff' => $cutoff]);
    $count = (int) $countStmt->fetchColumn();

    if ($count === 0) {
        return 0;
    }

    $updateStmt = $pdo->prepare(
        "UPDATE episodes
         SET status = 'published',
             updated_at = :cutoff
         WHERE status = 'scheduled' AND datetime(pub_date) <= datetime(:cutoff)"
    );
    $updateStmt->execute([':cutoff' => $cutoff]);

    return $count;
}

/**
 * Publica automáticamente los episodios programados cuya fecha ya ha llegado.
 *
 * Se ejecuta en cada petición web (lazy scheduling, sin cron).
 * Si no hay episodios pendientes no regenera nada.
 * Todo el bloque va en try/catch para que un fallo no rompa la petición.
 */
function publishScheduledEpisodes(string $dbPath): void
{
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $count = publishScheduledEpisodesInDatabase($pdo);

        if ($count === 0) {
            return;
        }

        // Regenerar feed.xml, sitemap.xml y limpiar caché tras publicar.
        try {
            writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
            writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
        } catch (Throwable $e) {
            // Silencioso: el episodio ya está publicado en BD.
        }
        clearWebCache();
    } catch (Throwable $e) {
        // Silencioso: no bloquear la petición.
    }
}
