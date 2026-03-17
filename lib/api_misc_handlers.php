<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/cache_management_handler.php';

/**
 * GET /api/v1/stats
 * Estadísticas básicas del podcast: episodios, caché y tamaño de audio.
 */
function apiGetStats(PDO $pdo, string $dbPath): void
{
    $published      = 0;
    $drafts         = 0;
    $total          = 0;
    $lastTitle      = '';
    $lastPubDate    = '';
    $audioSizeBytes = 0;

    $rows = $pdo->query(
        "SELECT status, COUNT(*) AS cnt FROM episodes GROUP BY status"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $cnt = (int) $row['cnt'];
        if ($row['status'] === 'published') {
            $published = $cnt;
        } else {
            $drafts += $cnt;
        }
        $total += $cnt;
    }

    $last = $pdo->query(
        "SELECT title, pub_date FROM episodes WHERE status = 'published'
         ORDER BY pub_date DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $lastTitle   = (string) $last['title'];
        $lastPubDate = (string) $last['pub_date'];
    }

    $sizeRow        = $pdo->query("SELECT COALESCE(SUM(audio_size_bytes), 0) AS total FROM episodes")->fetch(PDO::FETCH_ASSOC);
    $audioSizeBytes = (int) ($sizeRow['total'] ?? 0);

    $cacheEnabled  = isWebCacheEnabled($dbPath);
    $cacheFiles    = 0;
    $cacheSizeBytes = 0;
    $cacheDir      = cacheDirectoryPath();

    if (is_dir($cacheDir)) {
        $entries = @scandir($cacheDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $cacheDir . '/' . $entry;
            if (is_file($path)) {
                $cacheFiles++;
                $cacheSizeBytes += (int) @filesize($path);
            }
        }
    }

    apiJsonResponse([
        'success' => true,
        'data'    => [
            'episodes' => [
                'published'       => $published,
                'drafts'          => $drafts,
                'total'           => $total,
                'last_title'      => $lastTitle,
                'last_pub_date'   => $lastPubDate,
                'audio_size_bytes' => $audioSizeBytes,
            ],
            'cache' => [
                'enabled'    => $cacheEnabled,
                'files'      => $cacheFiles,
                'size_bytes' => $cacheSizeBytes,
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
