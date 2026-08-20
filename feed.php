<?php

declare(strict_types=1);

// Este endpoint devuelve el RSS XML en vivo generado desde la base de datos.
require_once __DIR__ . '/feed_builder.php';
require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/cache_service.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
if (tryServeWebCache($dbPath, 'application/rss+xml; charset=UTF-8')) {
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    if (activePodcast($pdo) === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo __('No hay un podcast definido para el feed principal.');
        exit;
    }
    // Prioriza la URL principal del podcast para atom:link/self.
    $selfHref = resolveFeedSelfHref($pdo);

    // Genera el XML completo en memoria y lo devuelve en esta request.
    $xml = buildPodcastFeedXml($pdo, $selfHref);

    header('Content-Type: application/rss+xml; charset=UTF-8');
    storeWebCache($dbPath, $xml);
    echo $xml;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error generando el feed: ' . $e->getMessage() . "\n";
}
