<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/migration_runner.php';
require_once __DIR__ . '/lib/podcast_context.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
runMigrations($dbPath);
$pdo = openPodcastDatabase($dbPath);
$settings = loadAppSettings($pdo);
$first = trim((string) ($_GET['first'] ?? ''));
$second = trim((string) ($_GET['second'] ?? ''));
$third = trim((string) ($_GET['third'] ?? ''));

if ($settings['multipodcast_enabled'] !== 1) {
    if ($third !== '') {
        http_response_code(404);
        require __DIR__ . '/404.php';
        exit;
    }
    $_GET['full_path'] = implode('/', array_filter([$first, $second], static fn(string $part): bool => $part !== ''));
    require __DIR__ . '/page.php';
    exit;
}

$podcast = podcastBySlug($pdo, $first);
if ($podcast === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$_GET['podcast_slug'] = $first;
if ($second === '') {
    require __DIR__ . '/index.php';
    exit;
}

$_GET['full_path'] = $third !== '' ? $second . '/' . $third : $second;
require __DIR__ . '/page.php';
