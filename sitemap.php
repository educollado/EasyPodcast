<?php

declare(strict_types=1);

// Endpoint dinámico para sitemap XML público.
require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/feed_builder.php';
require_once __DIR__ . '/lib/view_helpers.php';

// Construye la ruta pública de episodio usando año/mes y slug.
function buildEpisodePathForSitemap(string $pubDate, string $title): string
{
    $ts = strtotime($pubDate);
    if ($ts === false) {
        $ts = time();
    }

    $year = date('Y', $ts);
    $month = date('m', $ts);

    return '/' . $year . '/' . $month . '/' . slugify($title);
}

// Devuelve URL absoluta para una URL almacenada o una ruta relativa.
function toAbsoluteUrl(string $value, string $baseUrl): string
{
    $raw = trim($value);
    if ($raw === '') {
        return rtrim($baseUrl, '/') . '/';
    }

    $parts = parse_url($raw);
    if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        return $raw;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($raw, '/');
}

// Normaliza fecha para etiqueta <lastmod> en formato W3C.
function toSitemapLastmod(?string $value): string
{
    $ts = strtotime((string) $value);
    if ($ts === false) {
        $ts = time();
    }

    return date('c', $ts);
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $baseUrl = resolveBaseUrl($pdo);
    $podcast = $pdo->query('SELECT link FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: [];

    $homeUrl = toAbsoluteUrl((string) ($podcast['link'] ?? ''), $baseUrl);

    $episodesStmt = $pdo->prepare(
        "SELECT title, link, pub_date, updated_at
         FROM episodes
         WHERE status = 'published'
         ORDER BY datetime(pub_date) DESC, id DESC"
    );
    $episodesStmt->execute();
    $episodes = $episodesStmt->fetchAll();

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);

    $xml->startElement('urlset');
    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    $xml->startElement('url');
    $xml->writeElement('loc', $homeUrl);
    $xml->endElement();

    foreach ($episodes as $episode) {
        $storedLink = trim((string) ($episode['link'] ?? ''));
        $path = $storedLink !== '' ? $storedLink : buildEpisodePathForSitemap((string) ($episode['pub_date'] ?? ''), (string) ($episode['title'] ?? ''));
        $episodeUrl = toAbsoluteUrl($path, $baseUrl);

        $xml->startElement('url');
        $xml->writeElement('loc', $episodeUrl);
        $xml->writeElement('lastmod', toSitemapLastmod((string) ($episode['updated_at'] ?? $episode['pub_date'] ?? '')));
        $xml->endElement();
    }

    $xml->endElement();
    $xml->endDocument();

    header('Content-Type: application/xml; charset=UTF-8');
    echo $xml->outputMemory();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error generando sitemap: ' . $e->getMessage() . "\n";
}
