<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/view_helpers.php';

/**
 * Construye la ruta pública del episodio (/YYYY/MM/slug) para incluir en el sitemap.
 */
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

/**
 * Devuelve URL absoluta para una URL almacenada o una ruta relativa.
 * Si $value ya es absoluta (tiene scheme + host), la devuelve sin modificar.
 */
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

/**
 * Normaliza una fecha para la etiqueta <lastmod> del sitemap en formato ISO 8601 (W3C).
 * Si el valor no es parseable, usa la fecha actual.
 */
function toSitemapLastmod(?string $value): string
{
    $ts = strtotime((string) $value);
    if ($ts === false) {
        $ts = time();
    }

    return date('c', $ts);
}

/**
 * Genera el XML completo del sitemap usando los episodios publicados.
 * Incluye la portada y un <url> por episodio ordenado por fecha descendente.
 */
function buildPodcastSitemapXml(PDO $pdo): string
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $baseUrl = resolveBaseUrl($pdo);
    $podcast = $pdo->query('SELECT link FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: [];
    $homeUrl = toAbsoluteUrl((string) ($podcast['link'] ?? ''), $baseUrl);

    $episodes = [];
    $homeLastmodRaw = null;
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
        if ($episodes) {
            $homeLastmodRaw = (string) ($episodes[0]['updated_at'] ?? $episodes[0]['pub_date'] ?? '');
        }
    }

    if ($homeLastmodRaw === null || trim($homeLastmodRaw) === '') {
        $podcastColumns = $pdo->query('PRAGMA table_info(podcast)')->fetchAll();
        $hasPodcastUpdatedAt = false;
        foreach ($podcastColumns as $column) {
            if (($column['name'] ?? '') === 'updated_at') {
                $hasPodcastUpdatedAt = true;
                break;
            }
        }
        if ($hasPodcastUpdatedAt) {
            $podcastUpdated = $pdo->query('SELECT updated_at FROM podcast ORDER BY id ASC LIMIT 1')->fetchColumn();
            if (is_string($podcastUpdated) && trim($podcastUpdated) !== '') {
                $homeLastmodRaw = $podcastUpdated;
            }
        }
    }

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);

    $xml->startElement('urlset');
    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    $xml->startElement('url');
    $xml->writeElement('loc', $homeUrl);
    if ($homeLastmodRaw !== null && trim($homeLastmodRaw) !== '') {
        $xml->writeElement('lastmod', toSitemapLastmod($homeLastmodRaw));
    }
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

/**
 * Persiste el sitemap generado en un archivo estático sitemap.xml.
 * Usa escritura atómica (temporal + rename) para evitar que el fichero quede incompleto.
 *
 * @throws RuntimeException Si no se puede construir o escribir el sitemap.
 */
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
