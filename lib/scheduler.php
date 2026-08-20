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
function publishScheduledEpisodesInDatabase(PDO $pdo, ?string $cutoff = null, ?int $podcastId = null): int
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cutoff ??= scheduledPublicationCutoff();

    $scopeClause = $podcastId !== null ? 'podcast_id = :podcast_id AND ' : '';
    $params = [':cutoff' => $cutoff];
    if ($podcastId !== null) {
        $params[':podcast_id'] = $podcastId;
    }
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM episodes
         WHERE {$scopeClause}status = 'scheduled' AND datetime(pub_date) <= datetime(:cutoff)"
    );
    $countStmt->execute($params);
    $count = (int) $countStmt->fetchColumn();

    if ($count === 0) {
        return 0;
    }

    $updateStmt = $pdo->prepare(
        "UPDATE episodes
         SET status = 'published',
             updated_at = :cutoff
         WHERE {$scopeClause}status = 'scheduled' AND datetime(pub_date) <= datetime(:cutoff)"
    );
    $updateStmt->execute($params);

    return $count;
}

/**
 * Publica episodios programados vencidos y ejecuta los efectos secundarios asociados.
 *
 * Usa el mismo PDO del caller para evitar aperturas extra de conexión en flujos
 * que ya están consultando la base de datos.
 */
function publishScheduledEpisodesAndRefresh(PDO $pdo): int
{
    $podcast = activePodcast($pdo);
    if ($podcast === null) {
        return 0;
    }
    $podcastId = (int) $podcast['id'];
    $count = publishScheduledEpisodesInDatabase($pdo, null, $podcastId);

    if ($count === 0) {
        return 0;
    }

    try {
        writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
        writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
    } catch (Throwable $e) {
        // Silencioso: el episodio ya está publicado en BD.
    }
    clearWebCache();

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
        publishScheduledEpisodesAndRefresh($pdo);
    } catch (Throwable $e) {
        // Silencioso: no bloquear la petición.
    }
}
