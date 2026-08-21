<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';   // buildSafeFileName, buildEpisodePublicLink, normalizeDateTime, resolveAudioExtension, generateGuid
require_once __DIR__ . '/upload_service.php';    // mediaPublicBaseUrl()
require_once __DIR__ . '/view_helpers.php';       // esc()
require_once __DIR__ . '/cache_service.php';      // clearWebCache()
require_once __DIR__ . '/sitemap_builder.php';    // writePodcastSitemapFile()
require_once __DIR__ . '/../feed_builder.php';    // writePodcastFeedFile(), resolveBaseUrl(), resolveFeedSelfHref()
require_once __DIR__ . '/i18n.php';

const REMOTE_FEED_MAX_BYTES = 10485760;     // 10 MiB
const REMOTE_IMAGE_MAX_BYTES = 26214400;    // 25 MiB
const REMOTE_AUDIO_MAX_BYTES = 134217728;   // 128 MiB

// ---------------------------------------------------------------------------
// Descarga del XML del feed
// ---------------------------------------------------------------------------

/**
 * Opciones de cURL para limitar protocolos a HTTP/HTTPS.
 *
 * @return array<int, int>
 */
function remoteCurlProtocolOptions(): array
{
    $options = [];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    return $options;
}

/**
 * Resuelve las IPs asociadas a un host.
 *
 * @return array<int, string>
 */
function resolveHostIpAddresses(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];
    }

    $ips = gethostbynamel($host) ?: [];

    if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
        $aaaaRecords = dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                $ipv6 = trim((string) ($record['ipv6'] ?? ''));
                if ($ipv6 !== '') {
                    $ips[] = $ipv6;
                }
            }
        }
    }

    $ips = array_values(array_unique(array_filter(
        array_map('trim', $ips),
        static fn(string $ip): bool => $ip !== ''
    )));

    return $ips;
}

/**
 * Valida que una URL remota no apunte a redes privadas o reservadas.
 *
 * @return array{ok:bool,error:string,ips:array<int,string>}
 */
function validateRemoteFetchUrl(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['ok' => false, 'error' => __('La URL remota no es válida.'), 'ips' => []];
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'error' => __('La URL debe usar http o https.'), 'ips' => []];
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'error' => __('La URL remota no puede incluir credenciales.'), 'ips' => []];
    }

    $host = strtolower(trim((string) ($parts['host'] ?? '')));
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
        return ['ok' => false, 'error' => __('La URL remota apunta a un host no permitido.'), 'ips' => []];
    }

    $ips = resolveHostIpAddresses($host);
    if ($ips === []) {
        return ['ok' => false, 'error' => __('No se pudo resolver el host remoto.'), 'ips' => []];
    }

    foreach ($ips as $ip) {
        if (isPrivateOrReservedIp($ip)) {
            return [
                'ok' => false,
                'error' => __('La URL remota apunta a una red privada o reservada y ha sido bloqueada.'),
                'ips' => [],
            ];
        }
    }

    return ['ok' => true, 'error' => '', 'ips' => $ips];
}

/**
 * Fija en cURL una IP ya validada para impedir DNS rebinding entre la
 * comprobación de seguridad y la conexión real.
 */
function buildCurlResolveEntry(string $url, array $validatedIps): ?string
{
    $parts = parse_url($url);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $ip = (string) ($validatedIps[0] ?? '');
    if ($host === '' || $ip === '' || !in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $resolveHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ? '[' . $host . ']'
        : $host;
    $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
    return $resolveHost . ':' . $port . ':' . $address;
}

/**
 * Resuelve una cabecera Location relativa contra la URL actual.
 */
function resolveRedirectUrl(string $currentUrl, string $location): ?string
{
    $location = trim($location);
    if ($location === '') {
        return null;
    }

    if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
        return $location;
    }

    $parts = parse_url($currentUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $scheme = (string) $parts['scheme'];
    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }

    if (str_starts_with($location, '/')) {
        return $scheme . '://' . $host . $port . $location;
    }

    $path = (string) ($parts['path'] ?? '/');
    if (str_starts_with($location, '?')) {
        return $scheme . '://' . $host . $port . $path . $location;
    }

    $baseDir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
    $combined = $baseDir . $location;
    $segments = [];
    foreach (explode('/', $combined) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}

/**
 * Devuelve true si el código HTTP es una redirección.
 */
function isRedirectStatusCode(int $httpCode): bool
{
    return in_array($httpCode, [301, 302, 303, 307, 308], true);
}

/**
 * Extrae el valor de Location de un conjunto de cabeceras.
 *
 * @param array<int, string> $headers
 */
function findRedirectLocation(array $headers): string
{
    foreach ($headers as $header) {
        if (stripos($header, 'Location:') === 0) {
            return trim(substr($header, 9));
        }
    }

    return '';
}

/**
 * Descarga una respuesta de texto siguiendo redirecciones seguras.
 *
 * @return array{body:?string,error:string}
 */
function fetchRemoteTextResponse(string $url, int $timeout): array
{
    $currentUrl = $url;

    for ($redirects = 0; $redirects <= 5; $redirects++) {
        $validation = validateRemoteFetchUrl($currentUrl);
        if (!$validation['ok']) {
            return ['body' => null, 'error' => $validation['error']];
        }

        $resolveEntry = buildCurlResolveEntry($currentUrl, $validation['ips']);
        if ($resolveEntry === null) {
            return ['body' => null, 'error' => __('No se pudo fijar la resolución segura del host remoto.')];
        }

        $headers = [];
        $bodyBuffer = '';
        $tooLarge = false;
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $currentUrl,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'EasyPodcast/1.0 RSS Importer (+https://github.com/educollado/easypodcast)',
            CURLOPT_ENCODING => '',
            CURLOPT_RESOLVE => [$resolveEntry],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$bodyBuffer, &$tooLarge): int {
                if (strlen($bodyBuffer) + strlen($chunk) > REMOTE_FEED_MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $bodyBuffer .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headers): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    $headers[] = $trimmed;
                }
                return strlen($headerLine);
            },
        ] + remoteCurlProtocolOptions();
        curl_setopt_array($ch, $options);

        $ok = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tooLarge) {
            return ['body' => null, 'error' => __('El feed supera el tamaño máximo permitido de 10 MiB.')];
        }

        if ($ok === false || $curlError !== '') {
            return ['body' => null, 'error' => __('Error al descargar el feed: %s', $curlError)];
        }

        if (isRedirectStatusCode($httpCode)) {
            $location = findRedirectLocation($headers);
            $resolved = resolveRedirectUrl($currentUrl, $location);
            if ($resolved === null) {
                return ['body' => null, 'error' => __('La respuesta remota devolvió una redirección inválida.')];
            }
            $currentUrl = $resolved;
            continue;
        }

        if ($httpCode >= 400) {
            return ['body' => null, 'error' => __('El servidor devolvió HTTP %d.', $httpCode)];
        }

        if (trim($bodyBuffer) === '') {
            return ['body' => null, 'error' => __('El feed está vacío.')];
        }

        return ['body' => $bodyBuffer, 'error' => ''];
    }

    return ['body' => null, 'error' => __('La URL remota superó el número máximo de redirecciones permitidas.')];
}

/**
 * Descarga el XML de un feed RSS mediante cURL.
 * Devuelve ['xml' => string|null, 'error' => string].
 */
function fetchFeedXml(string $url): array
{
    $result = fetchRemoteTextResponse($url, 15);
    return ['xml' => $result['body'], 'error' => $result['error']];
}

// ---------------------------------------------------------------------------
// Parseo del feed
// ---------------------------------------------------------------------------

/**
 * Interpreta el valor de <itunes:explicit> como entero 0/1.
 */
function parseItunesExplicit(string $value): int
{
    return in_array(strtolower(trim($value)), ['yes', 'true'], true) ? 1 : 0;
}

/**
 * Normaliza un valor de duración RSS (HH:MM:SS, MM:SS o segundos enteros) a H:MM:SS.
 * Devuelve '' si no reconoce el formato.
 */
function parseDuration(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    // Formato colons: HH:MM:SS o MM:SS
    if (preg_match('/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $raw, $m)) {
        if (isset($m[3])) {
            $h   = (int) $m[1];
            $min = (int) $m[2];
            $sec = (int) $m[3];
        } else {
            $h   = 0;
            $min = (int) $m[1];
            $sec = (int) $m[2];
        }
        return sprintf('%d:%02d:%02d', $h, $min, $sec);
    }

    // Entero puro (segundos)
    if (ctype_digit($raw)) {
        $total = (int) $raw;
        $h     = intdiv($total, 3600);
        $min   = intdiv($total % 3600, 60);
        $sec   = $total % 60;
        return sprintf('%d:%02d:%02d', $h, $min, $sec);
    }

    return '';
}

/**
 * Extrae los metadatos del <channel> del feed.
 */
function parseFeedChannel(SimpleXMLElement $channel): array
{
    $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');

    // Imagen: primero itunes:image[@href], luego <image><url>
    $imageUrl = '';
    if (isset($itunes->image) && isset($itunes->image['href'])) {
        $imageUrl = trim((string) $itunes->image['href']);
    }
    if ($imageUrl === '' && isset($channel->image->url)) {
        $imageUrl = trim((string) $channel->image->url);
    }

    // Categoría: primer <itunes:category text="...">
    $category = '';
    if (isset($itunes->category) && isset($itunes->category['text'])) {
        $category = trim((string) $itunes->category['text']);
    }

    // Owner
    $ownerName  = '';
    $ownerEmail = '';
    if (isset($itunes->owner)) {
        $ownerNs    = $itunes->owner->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        $ownerName  = isset($ownerNs->name)  ? trim((string) $ownerNs->name)  : '';
        $ownerEmail = isset($ownerNs->email) ? trim((string) $ownerNs->email) : '';
    }

    return [
        'title'       => trim((string) ($channel->title       ?? '')),
        'description' => trim((string) ($channel->description ?? '')),
        'link'        => trim((string) ($channel->link        ?? '')),
        'language'    => trim((string) ($channel->language    ?? '')),
        'author'      => trim((string) ($itunes->author       ?? '')),
        'owner_name'  => $ownerName,
        'owner_email' => $ownerEmail,
        'category'    => $category,
        'explicit'    => parseItunesExplicit((string) ($itunes->explicit ?? '')),
        'image_url'   => $imageUrl,
        'copyright'   => trim((string) ($channel->copyright  ?? '')),
        'itunes_type' => trim((string) ($itunes->type         ?? '')),
    ];
}

/**
 * Extrae los datos de un <item> del feed.
 */
function parseFeedItem(SimpleXMLElement $item): array
{
    $itunes     = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
    $contentNs  = $item->children('http://purl.org/rss/1.0/modules/content/');

    // Contenido enriquecido: content:encoded > itunes:summary > description
    $episodeContent = '';
    if (isset($contentNs->encoded) && trim((string) $contentNs->encoded) !== '') {
        $episodeContent = trim((string) $contentNs->encoded);
    } elseif (isset($itunes->summary) && trim((string) $itunes->summary) !== '') {
        $episodeContent = trim((string) $itunes->summary);
    } else {
        $episodeContent = trim((string) ($item->description ?? ''));
    }
    $episodeContent = sanitizeRichHtml($episodeContent);

    // Enclosure
    $audioUrl  = '';
    $audioMime = '';
    $audioSize = 0;
    if (isset($item->enclosure)) {
        $audioUrl  = trim((string) ($item->enclosure['url']    ?? ''));
        $audioMime = trim((string) ($item->enclosure['type']   ?? ''));
        $audioSize = (int)        ($item->enclosure['length']  ?? 0);
    }

    // GUID: fallback a audio_url si está vacío
    $guid = trim((string) ($item->guid ?? ''));
    if ($guid === '') {
        $guid = $audioUrl;
    }

    // Fecha
    $pubDate = normalizeDateTime(trim((string) ($item->pubDate ?? '')));

    // Imagen del episodio
    $imageUrl = '';
    if (isset($itunes->image) && isset($itunes->image['href'])) {
        $imageUrl = trim((string) $itunes->image['href']);
    }

    return [
        'guid'           => $guid,
        'title'          => trim((string) ($item->title         ?? '')),
        'content'        => $episodeContent,
        'audio_url'      => $audioUrl,
        'audio_mime'     => $audioMime,
        'audio_size'     => $audioSize,
        'pub_date'       => $pubDate,
        'duration'       => parseDuration((string) ($itunes->duration     ?? '')),
        'image_url'      => $imageUrl,
        'episode_number' => trim((string) ($itunes->episode     ?? '')),
        'season_number'  => trim((string) ($itunes->season      ?? '')),
        'episode_type'   => trim((string) ($itunes->episodeType ?? '')),
        'author'         => trim((string) ($itunes->author      ?? '')),
        'explicit'       => parseItunesExplicit((string) ($itunes->explicit ?? '')),
        // Estado: published si tiene pubDate, draft si no
        'status'         => $pubDate !== null ? 'published' : 'draft',
    ];
}

/**
 * Parsea todos los <item> del canal y los ordena de más antiguo a más reciente.
 */
function parseFeedItems(SimpleXMLElement $channel): array
{
    $items = [];
    foreach ($channel->item as $item) {
        $items[] = parseFeedItem($item);
    }

    usort($items, static function (array $a, array $b): int {
        $ta = $a['pub_date'] !== null ? (int) strtotime($a['pub_date']) : 0;
        $tb = $b['pub_date'] !== null ? (int) strtotime($b['pub_date']) : 0;
        return $ta <=> $tb;
    });

    return $items;
}

/**
 * Parsea el feed completo y devuelve los datos de preview.
 * Devuelve ['preview' => [...], 'error' => ''].
 */
function loadFeedPreview(string $feedUrl): array
{
    $empty = ['preview' => null, 'error' => ''];

    if (!filter_var($feedUrl, FILTER_VALIDATE_URL)) {
        return array_merge($empty, ['error' => __('La URL del feed no es válida.')]);
    }

    $fetchResult = fetchFeedXml($feedUrl);
    if ($fetchResult['error'] !== '') {
        return array_merge($empty, ['error' => $fetchResult['error']]);
    }

    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $xml = simplexml_load_string(
        (string) $fetchResult['xml'],
        'SimpleXMLElement',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);

    if ($xml === false) {
        return array_merge($empty, ['error' => __('No se pudo parsear el XML del feed.')]);
    }

    if (!isset($xml->channel)) {
        return array_merge($empty, ['error' => __('El feed no contiene un elemento <channel>.')]);
    }

    $channel  = $xml->channel;
    $podcast  = parseFeedChannel($channel);
    $episodes = parseFeedItems($channel);

    return [
        'preview' => [
            'podcast'  => $podcast,
            'episodes' => $episodes,
        ],
        'error' => '',
    ];
}

// ---------------------------------------------------------------------------
// Descarga de ficheros
// ---------------------------------------------------------------------------

/**
 * Descarga un fichero desde URL a un directorio local mediante escritura limitada a disco.
 * Devuelve ['localPath' => string, 'localUrl' => string, 'mime' => string, 'size' => int, 'error' => ?string].
 */
function downloadFile(
    string $url,
    string $destDir,
    string $fallbackBase,
    int $timeout,
    string $baseUrl,
    int $maxBytes
): array
{
    $errorResult = ['localPath' => '', 'localUrl' => '', 'mime' => '', 'size' => 0, 'error' => ''];

    // La extensión remota no es fiable. Se usa una extensión inerte hasta que
    // downloadImage()/downloadAudio() validen el MIME real y renombren el fichero.
    $urlPath = (string) parse_url($url, PHP_URL_PATH);
    $originalName = basename($urlPath);
    $fileName     = buildSafeFileName($originalName, $fallbackBase, 'download');
    $localPath    = rtrim($destDir, '/') . '/' . $fileName;

    $currentUrl = $url;
    for ($redirects = 0; $redirects <= 10; $redirects++) {
        $validation = validateRemoteFetchUrl($currentUrl);
        if (!$validation['ok']) {
            $errorResult['error'] = $validation['error'];
            return $errorResult;
        }

        $resolveEntry = buildCurlResolveEntry($currentUrl, $validation['ips']);
        if ($resolveEntry === null) {
            $errorResult['error'] = __('No se pudo fijar la resolución segura del host remoto.');
            return $errorResult;
        }

        $fh = @fopen($localPath, 'wb');
        if ($fh === false) {
            $errorResult['error'] = __('No se pudo crear el fichero destino.');
            return $errorResult;
        }

        $headers = [];
        $writtenBytes = 0;
        $tooLarge = false;
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $currentUrl,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'EasyPodcast/1.0 RSS Importer (+https://github.com/educollado/easypodcast)',
            CURLOPT_FAILONERROR => false,
            CURLOPT_RESOLVE => [$resolveEntry],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($fh, $maxBytes, &$writtenBytes, &$tooLarge): int {
                $chunkLength = strlen($chunk);
                if ($writtenBytes + $chunkLength > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $written = fwrite($fh, $chunk);
                if ($written === false) {
                    return 0;
                }
                $writtenBytes += $written;
                return $written;
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headers): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    $headers[] = $trimmed;
                }
                return strlen($headerLine);
            },
        ] + remoteCurlProtocolOptions();
        curl_setopt_array($ch, $options);

        $ok = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if ($tooLarge) {
            @unlink($localPath);
            $errorResult['error'] = __('El fichero remoto supera el tamaño máximo permitido.');
            return $errorResult;
        }

        if ($ok === false || $curlError !== '') {
            @unlink($localPath);
            $errorResult['error'] = __('Error cURL: %s', $curlError);
            return $errorResult;
        }

        if (isRedirectStatusCode($httpCode)) {
            @unlink($localPath);
            $location = findRedirectLocation($headers);
            $resolved = resolveRedirectUrl($currentUrl, $location);
            if ($resolved === null) {
                $errorResult['error'] = __('La respuesta remota devolvió una redirección inválida.');
                return $errorResult;
            }
            $currentUrl = $resolved;
            continue;
        }

        if ($httpCode >= 400) {
            @unlink($localPath);
            $errorResult['error'] = __('El servidor devolvió HTTP %d.', $httpCode);
            return $errorResult;
        }

        if (!is_file($localPath) || filesize($localPath) === 0) {
            @unlink($localPath);
            $errorResult['error'] = __('El fichero descargado está vacío.');
            return $errorResult;
        }

        // MIME real del fichero descargado
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($localPath);
        $size  = (int) filesize($localPath);

        // La URL pública de medios siempre cuelga del origen, nunca del slug del podcast.
        $projectRoot = dirname(__DIR__);
        $normalizedPath = str_replace('\\', '/', $localPath);
        $kind = str_starts_with($normalizedPath, str_replace('\\', '/', $projectRoot . '/images/')) ? 'images' : 'audios';
        $localUrl = mediaPublicBaseUrl($baseUrl) . '/' . $kind . '/' . rawurlencode(basename($localPath));

        return [
            'localPath' => $localPath,
            'localUrl'  => $localUrl,
            'mime'      => $mime,
            'size'      => $size,
            'error'     => null,
        ];
    }

    $errorResult['error'] = __('La URL remota superó el número máximo de redirecciones permitidas.');
    return $errorResult;
}

/**
 * Renombra una descarga ya validada usando únicamente una extensión segura.
 */
function finalizeDownloadedFile(array $result, string $safeExtension): array
{
    $oldPath = (string) ($result['localPath'] ?? '');
    if ($oldPath === '' || !is_file($oldPath) || !preg_match('/^[a-z0-9]+$/', $safeExtension)) {
        $result['error'] = __('No se pudo finalizar el fichero descargado.');
        return $result;
    }

    $newName = pathinfo($oldPath, PATHINFO_FILENAME) . '.' . strtolower($safeExtension);
    $newPath = dirname($oldPath) . '/' . $newName;
    if (!@rename($oldPath, $newPath)) {
        @unlink($oldPath);
        $result['error'] = __('No se pudo asignar una extensión segura al fichero descargado.');
        return $result;
    }

    $localUrl = (string) ($result['localUrl'] ?? '');
    $result['localPath'] = $newPath;
    $result['localUrl'] = preg_replace('#[^/]+$#', rawurlencode($newName), $localUrl) ?? $localUrl;
    return $result;
}

/**
 * Descarga una imagen del feed al directorio /images/.
 * Verifica que el MIME sea de imagen antes de aceptar el fichero.
 */
function downloadImage(string $url, string $imagesDir, string $baseUrl, string $fallbackBase): array
{
    $result = downloadFile($url, $imagesDir, $fallbackBase, 30, $baseUrl, REMOTE_IMAGE_MAX_BYTES);
    if ($result['error'] !== null) {
        return $result;
    }

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMimes[$result['mime']])) {
        @unlink($result['localPath']);
        $result['error'] = __('MIME de imagen no válido: %s', $result['mime']);
        return $result;
    }

    return finalizeDownloadedFile($result, $allowedMimes[$result['mime']]);
}

/**
 * Descarga un audio del feed al directorio /audios/.
 * Verifica que el MIME sea de audio usando resolveAudioExtension().
 */
function downloadAudio(string $url, string $audiosDir, string $baseUrl, string $fallbackBase): array
{
    $result = downloadFile($url, $audiosDir, $fallbackBase, 300, $baseUrl, REMOTE_AUDIO_MAX_BYTES);
    if ($result['error'] !== null) {
        return $result;
    }

    // Verificar MIME usando el nombre del fichero descargado como fallback
    $origName = basename((string) parse_url($url, PHP_URL_PATH));
    $ext      = resolveAudioExtension($result['mime'], $origName);
    if ($ext === null) {
        @unlink($result['localPath']);
        $result['error'] = __('MIME de audio no válido: %s', $result['mime']);
        return $result;
    }

    return finalizeDownloadedFile($result, $ext);
}

// ---------------------------------------------------------------------------
// Streaming
// ---------------------------------------------------------------------------

/**
 * Emite una línea HTML con padding para forzar el flush en navegadores.
 */
function streamLine(string $html): void
{
    echo $html . "\n";
    echo str_repeat(' ', 1024) . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

// ---------------------------------------------------------------------------
// Importación principal
// ---------------------------------------------------------------------------

/**
 * Orquesta la importación completa con streaming HTML.
 * Emite un documento HTML completo (con nav) y finaliza con exit.
 *
 * @param string $dbPath         Ruta a la BD SQLite
 * @param string $feedUrl        URL del feed RSS
 * @param bool   $overwriteMeta  Si true, actualiza metadatos del podcast
 * @param bool   $skipExisting   Si true, salta episodios cuyo GUID ya está en BD
 * @param string $adminNavPath   Ruta absoluta a admin_nav.php para incluir el nav
 */
function runFeedImport(
    string $dbPath,
    string $feedUrl,
    bool $skipExisting,
    string $adminNavPath,
    array $selectedGuids = [],
    array $overwriteFields = []
): void {
    @set_time_limit(0);
    header('X-Accel-Buffering: no');
    header('Content-Type: text/html; charset=UTF-8');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    // HTML inicial
    echo '<!doctype html><html lang="' . esc(i18n_html_lang()) . '" data-theme="' . esc(adminTheme()) . '"><head>' . "\n";
    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . esc(__('Importando feed RSS')) . '</title>' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/admin-common.css">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/themes.css">' . "\n";
    echo '</head><body>' . "\n";

    // Barra de navegación
    $currentAdminPage = 'import';
    if (is_file($adminNavPath)) {
        require $adminNavPath;
    }

    echo '<div class="admin-wrap"><main class="card">' . "\n";
    echo '<h1>' . __('Importando feed RSS') . '</h1>' . "\n";
    flush();

    // --- 1. Parsear feed ---
    streamLine('<p>' . __('Descargando y parseando el feed…') . '</p>');

    $previewResult = loadFeedPreview($feedUrl);
    if ($previewResult['error'] !== '') {
        streamLine('<div class="error">' . esc($previewResult['error']) . '</div>');
        streamLine('<div class="actions"><a class="btn" href="import_feed.php">' . __('Volver') . '</a></div>');
        streamLine('</main></div></body></html>');
        exit;
    }

    $podcastMeta = $previewResult['preview']['podcast'];
    $episodes    = $previewResult['preview']['episodes'];

    // Filtrar por los episodios seleccionados por el usuario
    if (!empty($selectedGuids)) {
        $selectedSet = array_flip($selectedGuids);
        $episodes    = array_values(array_filter($episodes, static fn($ep) => isset($selectedSet[$ep['guid']])));
    }

    $total = count($episodes);

    streamLine('<p>' . __('Feed parseado: <strong>%d episodios</strong> seleccionados para importar.', $total) . '</p>');

    // --- 2. Conexión BD ---
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $baseUrl   = resolveBaseUrl($pdo);
    $activeImportPodcast = activePodcast($pdo) ?? [];
    $podcastId = (int) ($activeImportPodcast['id'] ?? 0);
    $imagesDir = podcastStorageDirectory(dirname(__DIR__), 'images', $activeImportPodcast, multipodcastEnabled($pdo));
    $audiosDir = podcastStorageDirectory(dirname(__DIR__), 'audios', $activeImportPodcast, multipodcastEnabled($pdo));

    // --- 3. Actualizar / insertar metadatos del podcast ---
    // 'link' excluido: la URL principal siempre se toma del host actual, nunca del feed externo.
    $allowedMetaFields = ['title', 'description', 'language', 'author',
                          'owner_name', 'owner_email', 'category', 'explicit',
                          'image_url', 'copyright', 'itunes_type'];
    $fieldsToUpdate = array_values(array_intersect($overwriteFields, $allowedMetaFields));

    $existingPodcastId = $podcastId > 0 ? $podcastId : false;

    if ($existingPodcastId === false) {
        // Primera importación: la tabla podcast está vacía → INSERT con todos los campos del feed.
        // Se ignora la selección de overwrite_fields porque no hay datos que proteger.
        if ($podcastMeta['image_url'] !== '') {
            streamLine('<p>' . __('Descargando imagen del podcast…') . '</p>');
            $imgResult = downloadImage($podcastMeta['image_url'], $imagesDir, $baseUrl, 'podcast-cover');
            if ($imgResult['error'] !== null) {
                streamLine('<p><em>⚠ ' . __('No se pudo descargar la imagen del podcast: %s', esc($imgResult['error'])) . '</em></p>');
            } else {
                $podcastMeta['image_url'] = $imgResult['localUrl'];
                streamLine('<p>✓ ' . __('Imagen del podcast descargada.') . '</p>');
            }
        }

        streamLine('<p>' . __('Creando datos del podcast desde el feed (primera importación)…') . '</p>');
        $pdo->prepare(
            'INSERT INTO podcast
             (title, description, link, language, author, owner_name, owner_email,
              category, explicit, image_url, copyright, itunes_type, admin_theme)
             VALUES
             (:title, :description, :link, :language, :author, :owner_name, :owner_email,
              :category, :explicit, :image_url, :copyright, :itunes_type, \'easypodcast\')'
        )->execute([
            ':title'       => $podcastMeta['title'],
            ':description' => $podcastMeta['description'],
            ':link'        => runtimeBaseUrl(),
            ':language'    => $podcastMeta['language'] !== '' ? $podcastMeta['language'] : 'es-ES',
            ':author'      => $podcastMeta['author']      !== '' ? $podcastMeta['author']      : null,
            ':owner_name'  => $podcastMeta['owner_name']  !== '' ? $podcastMeta['owner_name']  : null,
            ':owner_email' => $podcastMeta['owner_email'] !== '' ? $podcastMeta['owner_email'] : null,
            ':category'    => $podcastMeta['category']    !== '' ? $podcastMeta['category']    : null,
            ':explicit'    => $podcastMeta['explicit'],
            ':image_url'   => $podcastMeta['image_url']   !== '' ? $podcastMeta['image_url']   : null,
            ':copyright'   => $podcastMeta['copyright']   !== '' ? $podcastMeta['copyright']   : null,
            ':itunes_type' => $podcastMeta['itunes_type'] !== '' ? $podcastMeta['itunes_type'] : 'episodic',
        ]);
        streamLine('<p>✓ ' . __('Datos del podcast creados correctamente.') . '</p>');

    } elseif (!empty($fieldsToUpdate)) {
        // Fila existente: actualizar solo los campos seleccionados por el usuario.
        if (in_array('image_url', $fieldsToUpdate, true) && $podcastMeta['image_url'] !== '') {
            streamLine('<p>' . __('Descargando imagen del podcast…') . '</p>');
            $imgResult = downloadImage($podcastMeta['image_url'], $imagesDir, $baseUrl, 'podcast-cover');
            if ($imgResult['error'] !== null) {
                streamLine('<p><em>⚠ ' . __('No se pudo descargar la imagen del podcast: %s', esc($imgResult['error'])) . '</em></p>');
            } else {
                $podcastMeta['image_url'] = $imgResult['localUrl'];
                streamLine('<p>✓ ' . __('Imagen del podcast descargada.') . '</p>');
            }
        }

        streamLine('<p>' . __('Actualizando metadatos del podcast (%d campos)…', count($fieldsToUpdate)) . '</p>');
        $setClauses = [];
        $params     = [':id' => (int) $existingPodcastId];
        foreach ($fieldsToUpdate as $field) {
            $setClauses[]        = $field . ' = :' . $field;
            $params[':' . $field] = $podcastMeta[$field];
        }
        $pdo->prepare('UPDATE podcast SET ' . implode(', ', $setClauses) . ' WHERE id = :id')->execute($params);
        streamLine('<p>✓ ' . __('Metadatos del podcast actualizados.') . '</p>');

    } else {
        streamLine('<p><em>ℹ ' . __('Metadatos del podcast: ningún campo seleccionado — se omite la actualización.') . '</em></p>');
    }

    // --- 5. Bucle de episodios ---
    $imported = 0;
    $skipped  = 0;
    $errors   = 0;

    $checkGuidStmt = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE podcast_id = :podcast_id AND guid = :guid LIMIT 1');
    $insertStmt    = $pdo->prepare(
        'INSERT INTO episodes
         (podcast_id, guid, title, content, link, pub_date, audio_url, audio_mime_type, audio_size_bytes,
          duration, explicit, season_number, episode_number, episode_type, image_url, author, status, updated_at)
         VALUES
         (:podcast_id, :guid, :title, :content, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes,
          :duration, :explicit, :season_number, :episode_number, :episode_type, :image_url, :author, :status, datetime(\'now\'))'
    );

    streamLine('<hr><h2>' . __('Episodios') . '</h2>');
    streamLine('<ul class="import-stream-list">');

    foreach ($episodes as $i => $ep) {
        $num      = $i + 1;
        $epTitle  = $ep['title'] !== '' ? $ep['title'] : __('(sin título)');
        $titleEsc = esc($epTitle);

        // Comprobar duplicado por GUID
        if ($skipExisting) {
            $checkGuidStmt->execute([':podcast_id' => $podcastId, ':guid' => $ep['guid']]);
            if ((int) $checkGuidStmt->fetchColumn() > 0) {
                $skipped++;
                streamLine('<li class="import-stream-item">⏭ [' . $num . '/' . $total . '] ' . $titleEsc . ' — <em>' . __('saltado (GUID ya existe)') . '</em></li>');
                continue;
            }
        }

        // Sin enclosure de audio: saltar
        if ($ep['audio_url'] === '') {
            $errors++;
            streamLine('<li class="import-stream-item import-stream-item-error">✗ [' . $num . '/' . $total . '] ' . $titleEsc . ' — <em>' . __('sin enclosure de audio') . '</em></li>');
            continue;
        }

        // Descargar imagen del episodio (fallo no bloquea)
        $epImageUrl = $ep['image_url'];
        if ($epImageUrl !== '') {
            $epImgResult = downloadImage($epImageUrl, $imagesDir, $baseUrl, 'ep-img-' . $num);
            if ($epImgResult['error'] === null) {
                $epImageUrl = $epImgResult['localUrl'];
            }
        }

        // Descargar audio (fallo salta el episodio)
        streamLine('<li class="import-stream-item">⬇ [' . $num . '/' . $total . '] ' . $titleEsc . ' — ' . __('descargando audio…'));
        $audioResult = downloadAudio($ep['audio_url'], $audiosDir, $baseUrl, 'audio-ep-' . $num);
        if ($audioResult['error'] !== null) {
            $errors++;
            echo ' <strong class="import-stream-error">' . __('Error: %s', esc($audioResult['error'])) . '</strong></li>' . "\n";
            echo str_repeat(' ', 1024) . "\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            continue;
        }

        // Construir link público del episodio
        $link = buildEpisodePublicLink($baseUrl, $ep['pub_date'], $ep['title']);

        // GUID
        $guid = $ep['guid'] !== '' ? $ep['guid'] : generateGuid();

        // Resolver MIME final del audio
        $finalMime = $audioResult['mime'] !== '' ? $audioResult['mime'] : ($ep['audio_mime'] !== '' ? $ep['audio_mime'] : 'audio/mpeg');
        $finalSize = $audioResult['size'] > 0 ? $audioResult['size'] : $ep['audio_size'];

        // Insertar en BD
        try {
            $insertStmt->execute([
                ':podcast_id'       => $podcastId,
                ':guid'             => $guid,
                ':title'            => $ep['title'],
                ':content'          => $ep['content'],
                ':link'             => $link !== '' ? $link : null,
                ':pub_date'         => $ep['pub_date'],
                ':audio_url'        => $audioResult['localUrl'],
                ':audio_mime_type'  => $finalMime,
                ':audio_size_bytes' => $finalSize,
                ':duration'         => $ep['duration'] !== '' ? $ep['duration'] : null,
                ':explicit'         => $ep['explicit'],
                ':season_number'    => $ep['season_number'] !== '' ? (int) $ep['season_number'] : null,
                ':episode_number'   => $ep['episode_number'] !== '' ? (int) $ep['episode_number'] : null,
                ':episode_type'     => $ep['episode_type'] !== '' ? $ep['episode_type'] : null,
                ':image_url'        => $epImageUrl !== '' ? $epImageUrl : null,
                ':author'           => $ep['author'] !== '' ? $ep['author'] : null,
                ':status'           => $ep['status'],
            ]);
            $imported++;
            echo ' ✓</li>' . "\n";
            echo str_repeat(' ', 1024) . "\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        } catch (Throwable $e) {
            $errors++;
            echo ' <strong class="import-stream-error">' . __('Error BD: %s', esc($e->getMessage())) . '</strong></li>' . "\n";
            echo str_repeat(' ', 1024) . "\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }

    streamLine('</ul><hr>');

    // --- 6. Regenerar feed.xml, sitemap.xml y caché ---
    streamLine('<p>' . __('Regenerando feed.xml y sitemap.xml…') . '</p>');
    try {
        writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
        writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
        streamLine('<p>✓ ' . __('feed.xml y sitemap.xml regenerados.') . '</p>');
    } catch (Throwable $e) {
        streamLine('<p><em>⚠ ' . __('No se pudo regenerar feed/sitemap: %s', esc($e->getMessage())) . '</em></p>');
    }

    clearWebCache();
    streamLine('<p>✓ ' . __('Caché borrada.') . '</p>');

    // --- 7. Resumen final ---
    streamLine('<div class="notice"><strong>' . __('Importación completada:') . '</strong> '
        . __('%d importados, %d saltados, %d errores.', $imported, $skipped, $errors) . '</div>');

    streamLine('<div class="actions">');
    streamLine('<a class="btn" href="episodes_management.php">' . __('Ver episodios') . '</a>');
    streamLine('<a class="btn" href="import_feed.php">' . __('Importar otro feed') . '</a>');
    streamLine('</div>');

    streamLine('</main></div></body></html>');
    exit;
}
