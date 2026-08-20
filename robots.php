<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/seo_helpers.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';

$podcastLink = null;
$sitemapLines = [];
try {
    enforceCanonicalHostFromPodcastLink($dbPath);
    $pdo = openPodcastDatabase($dbPath);
    $podcast = activePodcast($pdo) ?? firstPodcast($pdo);
    $podcastLink = $podcast['link'] ?? null;
    $baseUrl = rtrim(resolveSeoBaseUrl($podcastLink), '/');
    $settings = loadAppSettings($pdo);
    if ($settings['multipodcast_enabled'] === 1 && $settings['homepage_podcast_id'] === null) {
        foreach ($pdo->query("SELECT slug FROM podcast WHERE slug IS NOT NULL AND slug != '' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $sitemapLines[] = 'Sitemap: ' . $baseUrl . '/' . rawurlencode((string) $slug) . '/sitemap.xml';
        }
    } else {
        $sitemapLines[] = 'Sitemap: ' . $baseUrl . '/sitemap.xml';
    }
} catch (Throwable) {
    $baseUrl = rtrim(resolveSeoBaseUrl(null), '/');
    $sitemapLines[] = 'Sitemap: ' . $baseUrl . '/sitemap.xml';
}
$sitemaps = implode("\n", $sitemapLines);

header('Content-Type: text/plain; charset=UTF-8');
echo <<<ROBOTS
# Reglas generales para todos los rastreadores
User-agent: *

# Mantener acceso a la parte publica
Allow: /

# Reducir frecuencia de rastreo (en segundos)
Crawl-delay: 120

# Bloquear rutas de administracion
Disallow: /admin.php
Disallow: /add_episode.php
Disallow: /episodes_management.php
Disallow: /podcast_management.php
Disallow: /backups.php

# Mapa del sitio para indexacion
{$sitemaps}
ROBOTS;
