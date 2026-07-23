<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/episode_helpers.php';

// Generador RSS compartido usado por:
// - feed.php para salida dinámica
// - flujos de administración para regenerar feed.xml tras cambios

/**
 * Convierte una fecha de BD o publicación al formato RFC 2822 requerido por RSS.
 * Si el valor está vacío o no es parseable, devuelve la fecha actual.
 */
function toRssDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return date(DATE_RSS);
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return date(DATE_RSS);
    }

    return date(DATE_RSS, $ts);
}

/**
 * Devuelve "yes" o "no" para la etiqueta itunes:explicit.
 * Si $value es null, usa $fallback (valor del podcast padre) como valor efectivo.
 */
function boolToItunesExplicit(?int $value, int $fallback = 0): string
{
    $effective = $value;
    if ($effective === null) {
        $effective = $fallback;
    }

    return ((int) $effective) === 1 ? 'yes' : 'no';
}

/**
 * Escribe un elemento de texto XML solo si el valor no está vacío.
 * Evita añadir etiquetas vacías para campos opcionales del feed.
 */
function writeTextIfNotEmpty(XMLWriter $xml, string $name, ?string $value): void
{
    if ($value === null || trim($value) === '') {
        return;
    }

    $xml->writeElement($name, $value);
}

/**
 * Infiere el MIME de audio por la extensión de la URL cuando el MIME almacenado es genérico.
 * Devuelve null si la extensión no es reconocida.
 */
function guessAudioMimeFromUrl(string $audioUrl): ?string
{
    $path = (string) parse_url($audioUrl, PHP_URL_PATH);
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'webm' => 'audio/webm',
    ];
    return $map[$ext] ?? null;
}

/**
 * Asegura que el MIME del enclosure sea compatible con plataformas de podcast.
 * Sustituye MIMEs vacíos o genéricos (application/octet-stream) por el inferido de la URL.
 */
function normalizeEnclosureMime(?string $storedMime, string $audioUrl): string
{
    $mime = strtolower(trim((string) $storedMime));
    if ($mime === '' || $mime === 'application/octet-stream') {
        return guessAudioMimeFromUrl($audioUrl) ?? 'audio/mpeg';
    }

    return $mime;
}

/**
 * Extrae esquema + host (+ puerto opcional) de una URL para usarla como base canónica.
 * Devuelve null si la URL está vacía o no tiene host válido.
 */
function extractBaseUrlFromLink(?string $rawUrl): ?string
{
    $value = trim((string) $rawUrl);
    if ($value === '') {
        return null;
    }

    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    return $scheme . '://' . $host . $port;
}

/**
 * Devuelve la URL base construida desde el host y esquema de la request actual.
 * Se usa como fallback cuando podcast.link no está configurado.
 */
function runtimeBaseUrl(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

/**
 * Resuelve la URL base preferida usando podcast.link si está configurado.
 * Cae en la URL del host actual como fallback si la tabla o el campo no existen.
 */
function resolveBaseUrl(PDO $pdo): string
{
    $tableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'podcast' LIMIT 1")
        ->fetchColumn();

    if ($tableExists) {
        $podcast = $pdo->query('SELECT link FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
        if ($podcast) {
            $fromLink = extractBaseUrlFromLink((string) ($podcast['link'] ?? ''));
            if ($fromLink !== null) {
                return $fromLink;
            }
        }
    }

    return runtimeBaseUrl();
}

/**
 * Devuelve la URL canónica del feed (base + /feed.xml) para la etiqueta atom:link rel="self".
 */
function resolveFeedSelfHref(PDO $pdo): string
{
    return rtrim(resolveBaseUrl($pdo), '/') . '/feed.xml';
}

/**
 * Construye la URL de tracking para descargas iniciadas desde el feed RSS.
 * El endpoint redirige al audio real y permite registrar la acción como "feed".
 */
function buildFeedTrackingUrl(string $baseUrl, int $episodeId): string
{
    return rtrim($baseUrl, '/') . '/track.php?' . http_build_query([
        'episode_id' => $episodeId,
        'action' => 'feed',
    ]);
}

/**
 * Devuelve la URL de tracking para descargas iniciadas desde el feed RSS.
 * Usa la base canónica del podcast y delega en un helper puro.
 */
function resolveFeedTrackingUrl(PDO $pdo, int $episodeId): string
{
    return buildFeedTrackingUrl(resolveBaseUrl($pdo), $episodeId);
}

/**
 * Construye un documento XML RSS 2.0 + iTunes completo desde la BD actual.
 * Respeta rss_item_limit (0 = sin límite). Lanza RuntimeException si no hay datos de podcast.
 *
 * @throws RuntimeException Si no existe ningún registro en la tabla podcast.
 */
function buildPodcastFeedXml(PDO $pdo, string $selfHref): string
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $podcast = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
    if (!$podcast) {
        throw new RuntimeException('No se encontró ningún registro en la tabla podcast.');
    }

    $rssItemLimit = max(0, (int) ($podcast['rss_item_limit'] ?? 0));
    if ($rssItemLimit > 0) {
        $episodesStmt = $pdo->prepare(
            "SELECT *
             FROM episodes
             WHERE status = 'published'
             ORDER BY datetime(pub_date) DESC
             LIMIT :limit"
        );
        $episodesStmt->bindValue(':limit', $rssItemLimit, PDO::PARAM_INT);
        $episodesStmt->execute();
    } else {
        $episodesStmt = $pdo->prepare(
            "SELECT *
             FROM episodes
             WHERE status = 'published'
             ORDER BY datetime(pub_date) DESC"
        );
        $episodesStmt->execute();
    }
    $episodes = $episodesStmt->fetchAll();

    $latestEpisodeDate = null;
    if ($episodes) {
        $latestEpisodeDate = $episodes[0]['pub_date'] ?? null;
    }

    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->setIndent(true);

    $xml->startElement('rss');
    $xml->writeAttribute('version', '2.0');
    $xml->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
    $xml->writeAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
    $xml->writeAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');

    $xml->startElement('channel');

    $xml->writeElement('title', (string) $podcast['title']);
    $xml->writeElement('link', (string) $podcast['link']);
    $xml->startElement('description');
    $xml->writeCdata((string) ($podcast['description'] ?? ''));
    $xml->endElement();

    writeTextIfNotEmpty($xml, 'language', $podcast['language'] ?? 'es-ES');
    writeTextIfNotEmpty($xml, 'copyright', $podcast['copyright'] ?? null);

    $xml->writeElement('pubDate', toRssDate($latestEpisodeDate));
    $xml->writeElement('lastBuildDate', date(DATE_RSS));

    $xml->startElement('atom:link');
    $xml->writeAttribute('href', $selfHref);
    $xml->writeAttribute('rel', 'self');
    $xml->writeAttribute('type', 'application/rss+xml');
    $xml->endElement();

    writeTextIfNotEmpty($xml, 'itunes:author', $podcast['author'] ?? null);
    $xml->writeElement('itunes:explicit', boolToItunesExplicit((int) ($podcast['explicit'] ?? 0)));
    writeTextIfNotEmpty($xml, 'itunes:type', $podcast['itunes_type'] ?? 'episodic');

    if (!empty($podcast['owner_name']) || !empty($podcast['owner_email'])) {
        $xml->startElement('itunes:owner');
        writeTextIfNotEmpty($xml, 'itunes:name', $podcast['owner_name'] ?? null);
        writeTextIfNotEmpty($xml, 'itunes:email', $podcast['owner_email'] ?? null);
        $xml->endElement();
    }

    if (!empty($podcast['category'])) {
        $categories = array_filter(array_map('trim', explode(',', (string) $podcast['category'])));
        foreach ($categories as $category) {
            $xml->startElement('itunes:category');
            $xml->writeAttribute('text', $category);
            $xml->endElement();
        }
    }

    if (!empty($podcast['image_url'])) {
        $xml->startElement('itunes:image');
        $xml->writeAttribute('href', (string) $podcast['image_url']);
        $xml->endElement();

        $xml->startElement('image');
        $xml->writeElement('url', (string) $podcast['image_url']);
        $xml->writeElement('title', (string) $podcast['title']);
        $xml->writeElement('link', (string) $podcast['link']);
        $xml->endElement();
    }

    $podcastExplicit = (int) ($podcast['explicit'] ?? 0);

    // Cada episodio publicado se renderiza como un <item>.
    foreach ($episodes as $episode) {
        $xml->startElement('item');

        $xml->writeElement('title', (string) $episode['title']);

        // description e itunes:summary usan short_description (texto plano).
        // content:encoded contiene el HTML completo del episodio.
        if (!empty($episode['short_description'])) {
            $xml->startElement('description');
            $xml->writeCdata((string) $episode['short_description']);
            $xml->endElement();
            writeTextIfNotEmpty($xml, 'itunes:summary', (string) $episode['short_description']);
        }

        $xml->startElement('content:encoded');
        $xml->writeCdata(sanitizeRichHtml((string) ($episode['content'] ?? '')));
        $xml->endElement();

        $episodeLink = $episode['link'] ?: $episode['audio_url'];
        writeTextIfNotEmpty($xml, 'link', $episodeLink);

        $xml->startElement('guid');
        $xml->writeAttribute('isPermaLink', 'false');
        $xml->text((string) $episode['guid']);
        $xml->endElement();

        $xml->writeElement('pubDate', toRssDate($episode['pub_date'] ?? null));

        $audioUrl = resolveFeedTrackingUrl($pdo, (int) ($episode['id'] ?? 0));
        $enclosureMime = normalizeEnclosureMime($episode['audio_mime_type'] ?? null, (string) $episode['audio_url']);

        $xml->startElement('enclosure');
        $xml->writeAttribute('url', $audioUrl);
        $xml->writeAttribute('length', (string) ((int) ($episode['audio_size_bytes'] ?? 0)));
        $xml->writeAttribute('type', $enclosureMime);
        $xml->endElement();

        $duration = resolveEpisodeDuration((string) ($episode['duration'] ?? ''), (string) ($episode['audio_url'] ?? ''));
        writeTextIfNotEmpty($xml, 'itunes:duration', $duration !== '' ? $duration : null);
        writeTextIfNotEmpty($xml, 'itunes:author', $episode['author'] ?? null);
        $xml->writeElement('itunes:explicit', boolToItunesExplicit(
            isset($episode['explicit']) ? (int) $episode['explicit'] : null,
            $podcastExplicit
        ));

        if (!empty($episode['season_number'])) {
            $xml->writeElement('itunes:season', (string) ((int) $episode['season_number']));
        }

        if (!empty($episode['episode_number'])) {
            $xml->writeElement('itunes:episode', (string) ((int) $episode['episode_number']));
        }

        if (!empty($episode['episode_type'])) {
            $xml->writeElement('itunes:episodeType', (string) $episode['episode_type']);
        }

        if (!empty($episode['image_url'])) {
            $xml->startElement('itunes:image');
            $xml->writeAttribute('href', (string) $episode['image_url']);
            $xml->endElement();
        }

        $xml->endElement();
    }

    $xml->endElement();
    $xml->endElement();
    $xml->endDocument();

    return $xml->outputMemory();
}

/**
 * Persiste el RSS generado en el archivo feed.xml estático indicado por $filePath.
 *
 * @throws RuntimeException Si no se puede escribir el fichero.
 */
function writePodcastFeedFile(PDO $pdo, string $filePath, string $selfHref): void
{
    $xml = buildPodcastFeedXml($pdo, $selfHref);
    $result = @file_put_contents($filePath, $xml);
    if ($result === false) {
        throw new RuntimeException('No se pudo escribir ' . basename($filePath) . '.');
    }
}
