<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/sitemap_builder.php';
require_once __DIR__ . '/cache_service.php';

/**
 * Publica automáticamente los episodios programados cuya fecha ya ha llegado.
 *
 * Se ejecuta en cada petición web (lazy scheduling, sin cron).
 * Si no hay episodios pendientes la query COUNT es barata y no hace nada más.
 * Todo el bloque va en try/catch para que un fallo no rompa la petición.
 */
function publishScheduledEpisodes(string $dbPath): void
{
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $count = (int) $pdo
            ->query("SELECT COUNT(*) FROM episodes WHERE status='scheduled' AND datetime(pub_date) <= datetime('now')")
            ->fetchColumn();

        if ($count === 0) {
            return;
        }

        $pdo->exec(
            "UPDATE episodes SET status='published', updated_at=datetime('now')
             WHERE status='scheduled' AND datetime(pub_date) <= datetime('now')"
        );

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
