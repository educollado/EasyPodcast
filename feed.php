<?php

declare(strict_types=1);

// Este endpoint devuelve el RSS XML en vivo generado desde la base de datos.
require_once __DIR__ . '/feed_builder.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    // Prioriza la URL canónica del feed estático en atom:link/self.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $selfHref = $scheme . '://' . $host . '/feed.xml';

    $xml = buildPodcastFeedXml($pdo, $selfHref);

    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo $xml;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error generando el feed: ' . $e->getMessage() . "\n";
}
