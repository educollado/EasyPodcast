<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';

// Slug simple para sitemap sin depender de view_helpers.php.
function slugifyForSitemap(string $value): string
{
    $slug = trim($value);
    if ($slug === '') {
        return 'capitulo';
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($converted !== false) {
            $slug = $converted;
        }
    }

    $slug = strtolower($slug);
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'capitulo';
}

// Construye la ruta pública de episodio usando año/mes y slug.
function buildEpisodePathForSitemap(string $pubDate, string $title): string
{
    $ts = strtotime($pubDate);
    if ($ts === false) {
        $ts = time();
    }

    $year = date('Y', $ts);
    $month = date('m', $ts);

    return '/' . $year . '/' . $month . '/' . slugifyForSitemap($title);
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

// Genera el XML completo de sitemap usando los episodios publicados.
function buildPodcastSitemapXml(PDO $pdo): string
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $baseUrl = resolveBaseUrl($pdo);
    $podcast = $pdo->query('SELECT link FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: [];
    $homeUrl = toAbsoluteUrl((string) ($podcast['link'] ?? ''), $baseUrl);

    $episodes = [];
    $episodesTableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'episodes' LIMIT 1")
        ->fetchColumn();
    if ($episodesTableExists) {
        $episodesStmt = $pdo->prepare(
            "SELECT title, link, pub_date, updated_at
             FROM episodes
             WHERE status = 'published'
             ORDER BY datetime(pub_date) DESC, id DESC"
        );
        $episodesStmt->execute();
        $episodes = $episodesStmt->fetchAll();
    }

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
        $path = $storedLink !== ''
            ? $storedLink
            : buildEpisodePathForSitemap((string) ($episode['pub_date'] ?? ''), (string) ($episode['title'] ?? ''));
        $episodeUrl = toAbsoluteUrl($path, $baseUrl);

        $xml->startElement('url');
        $xml->writeElement('loc', $episodeUrl);
        $xml->writeElement('lastmod', toSitemapLastmod((string) ($episode['updated_at'] ?? $episode['pub_date'] ?? '')));
        $xml->endElement();
    }

    $xml->endElement();
    $xml->endDocument();

    return $xml->outputMemory();
}

// Persiste el sitemap en un archivo estático sitemap.xml.
function writePodcastSitemapFile(PDO $pdo, string $targetPath): void
{
    $xml = buildPodcastSitemapXml($pdo);
    if ($xml === '') {
        throw new RuntimeException('No se pudo construir sitemap.xml');
    }

    $tmpSuffix = bin2hex(random_bytes(4));
    $tmpPath = $targetPath . '.tmp-' . $tmpSuffix;
    if (file_put_contents($tmpPath, $xml) === false) {
        throw new RuntimeException('No se pudo escribir el temporal de sitemap.xml');
    }

    if (!rename($tmpPath, $targetPath)) {
        @unlink($tmpPath);
        throw new RuntimeException('No se pudo reemplazar sitemap.xml');
    }
}
