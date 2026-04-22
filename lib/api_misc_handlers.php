<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/cache_management_handler.php';
require_once __DIR__ . '/stats_handler.php';
require_once __DIR__ . '/stats_downloads_handler.php';

/**
 * GET /api/v1/stats
 * Estadísticas del podcast: resumen general, caché y descargas.
 */
function apiGetStats(PDO $pdo, string $dbPath): void
{
    $filterYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
    $overview = getStatsOverview($pdo);
    $cache = getCacheStatsData($dbPath);
    $downloads = getDownloadsStatsData($pdo, $filterYear);

    apiJsonResponse([
        'success' => true,
        'data'    => [
            'episodes' => [
                'published' => (int) $overview['published'],
                'drafts' => (int) $overview['drafts'],
                'total' => (int) $overview['total'],
                'last_title' => (string) $overview['lastTitle'],
                'last_pub_date' => (string) $overview['lastPubDate'],
                'audio_size_bytes' => (int) $overview['audioSizeBytes'],
                'audio_size_human' => statsFormatBytes((int) $overview['audioSizeBytes']),
            ],
            'cache' => [
                'enabled' => (bool) $cache['cacheEnabled'],
                'files' => (int) $cache['cacheFiles'],
                'size_bytes' => (int) $cache['cacheSizeBytes'],
                'size_human' => statsFormatBytes((int) $cache['cacheSizeBytes']),
            ],
            'downloads' => [
                'filter_year' => $downloads['filter_year'],
                'available_years' => $downloads['available_years'],
                'daily' => $downloads['daily'],
                'monthly' => $downloads['monthly'],
                'yearly' => $downloads['yearly'],
                'summary' => $downloads['summary'],
            ],
        ],
    ]);
}

/**
 * POST /api/v1/cache/clear
 * Limpia la caché web y de imágenes.
 */
function apiClearCache(): void
{
    $webOk   = clearWebCache();
    $imageOk = clearImageCache();

    if ($webOk && $imageOk) {
        apiJsonResponse(['success' => true, 'data' => ['message' => 'Caché borrada correctamente.']]);
    } else {
        apiError('No se pudo borrar completamente la caché.', 500);
    }
}

/**
 * POST /api/v1/cache/regenerate-images
 * Regenera todas las variantes de imagen.
 */
function apiRegenerateImages(PDO $pdo): void
{
    if (!clearImageCache()) {
        apiError('No se pudo limpiar la caché de imágenes antes de regenerar.', 500);
    }

    $count = regenerateAllImages($pdo);

    apiJsonResponse([
        'success' => true,
        'data'    => ['message' => "Imágenes regeneradas: $count fuente(s) procesada(s).", 'count' => $count],
    ]);
}

/**
 * POST /api/v1/feed/regenerate
 * Regenera feed.xml y sitemap.xml.
 */
function apiFeedRegenerate(PDO $pdo): void
{
    require_once __DIR__ . '/../feed_builder.php';
    require_once __DIR__ . '/sitemap_builder.php';

    try {
        writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
        writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
    } catch (Throwable $e) {
        apiError('Error al regenerar el feed: ' . $e->getMessage(), 500);
    }

    clearWebCache();

    apiJsonResponse(['success' => true, 'data' => ['message' => 'feed.xml y sitemap.xml regenerados correctamente.']]);
}
