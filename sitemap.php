<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/sitemap_builder.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
try {
    $pdo = openPodcastDatabase($dbPath);
    if (activePodcast($pdo) === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/xml; charset=UTF-8');
    echo buildPodcastSitemapXml($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo __('No se pudo generar el sitemap.');
}
