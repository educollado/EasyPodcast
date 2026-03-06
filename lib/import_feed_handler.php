<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';   // buildSafeFileName, buildEpisodePublicLink, normalizeDateTime, resolveAudioExtension, generateGuid
require_once __DIR__ . '/view_helpers.php';       // esc()
require_once __DIR__ . '/cache_service.php';      // clearWebCache()
require_once __DIR__ . '/sitemap_builder.php';    // writePodcastSitemapFile()
require_once __DIR__ . '/../feed_builder.php';    // writePodcastFeedFile(), resolveBaseUrl(), resolveFeedSelfHref()

// ---------------------------------------------------------------------------
// Descarga del XML del feed
// ---------------------------------------------------------------------------

/**
 * Descarga el XML de un feed RSS mediante cURL.
 * Devuelve ['xml' => string|null, 'error' => string].
 */
function fetchFeedXml(string $url): array
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['xml' => null, 'error' => 'La URL debe usar http o https.'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'EasyPodcast/1.0 RSS Importer (+https://github.com/educollado/easypodcast)',
        CURLOPT_ENCODING       => '',
    ]);

    $body      = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        return ['xml' => null, 'error' => 'Error al descargar el feed: ' . $curlError];
    }

    if ($httpCode >= 400) {
        return ['xml' => null, 'error' => 'El servidor devolvió HTTP ' . $httpCode . '.'];
    }

    if (!is_string($body) || trim($body) === '') {
        return ['xml' => null, 'error' => 'El feed está vacío.'];
    }

    return ['xml' => $body, 'error' => ''];
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
    $itunes  = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
    $content = $item->children('http://purl.org/rss/1.0/modules/content/');

    // Descripción enriquecida: content:encoded > itunes:summary > description
    $description = '';
    if (isset($content->encoded) && trim((string) $content->encoded) !== '') {
        $description = trim((string) $content->encoded);
    } elseif (isset($itunes->summary) && trim((string) $itunes->summary) !== '') {
        $description = trim((string) $itunes->summary);
    } else {
        $description = trim((string) ($item->description ?? ''));
    }

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
        'description'    => $description,
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
        return array_merge($empty, ['error' => 'La URL del feed no es válida.']);
    }

    $fetchResult = fetchFeedXml($feedUrl);
    if ($fetchResult['error'] !== '') {
        return array_merge($empty, ['error' => $fetchResult['error']]);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string) $fetchResult['xml']);
    libxml_clear_errors();

    if ($xml === false) {
        return array_merge($empty, ['error' => 'No se pudo parsear el XML del feed.']);
    }

    if (!isset($xml->channel)) {
        return array_merge($empty, ['error' => 'El feed no contiene un elemento <channel>.']);
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
 * Descarga un fichero desde URL a un directorio local usando CURLOPT_FILE (directo a disco).
 * Devuelve ['localPath' => string, 'localUrl' => string, 'mime' => string, 'size' => int, 'error' => ?string].
 */
function downloadFile(string $url, string $destDir, string $fallbackBase, int $timeout, string $baseUrl): array
{
    $errorResult = ['localPath' => '', 'localUrl' => '', 'mime' => '', 'size' => 0, 'error' => ''];

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        $errorResult['error'] = 'URL no válida (debe ser http/https).';
        return $errorResult;
    }

    // Extensión desde la URL para el nombre del fichero destino
    $urlPath = (string) parse_url($url, PHP_URL_PATH);
    $ext     = strtolower((string) pathinfo($urlPath, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 5) {
        $ext = 'bin';
    }

    $originalName = basename($urlPath);
    $fileName     = buildSafeFileName($originalName, $fallbackBase, $ext);
    $localPath    = rtrim($destDir, '/') . '/' . $fileName;

    $fh = @fopen($localPath, 'wb');
    if ($fh === false) {
        $errorResult['error'] = 'No se pudo crear el fichero destino.';
        return $errorResult;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'EasyPodcast/1.0 RSS Importer (+https://github.com/educollado/easypodcast)',
        CURLOPT_FAILONERROR    => false,
    ]);

    $ok        = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($ok === false || $curlError !== '') {
        @unlink($localPath);
        $errorResult['error'] = 'Error cURL: ' . $curlError;
        return $errorResult;
    }

    if ($httpCode >= 400) {
        @unlink($localPath);
        $errorResult['error'] = 'El servidor devolvió HTTP ' . $httpCode . '.';
        return $errorResult;
    }

    if (!is_file($localPath) || filesize($localPath) === 0) {
        @unlink($localPath);
        $errorResult['error'] = 'El fichero descargado está vacío.';
        return $errorResult;
    }

    // MIME real del fichero descargado
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($localPath);
    $size  = (int) filesize($localPath);

    // URL pública local: base + ruta relativa desde la raíz del proyecto
    $projectRoot = dirname(__DIR__);
    $relativePath = str_replace($projectRoot, '', $localPath);
    $localUrl = rtrim($baseUrl, '/') . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');

    return [
        'localPath' => $localPath,
        'localUrl'  => $localUrl,
        'mime'      => $mime,
        'size'      => $size,
        'error'     => null,
    ];
}

/**
 * Descarga una imagen del feed al directorio /images/.
 * Verifica que el MIME sea de imagen antes de aceptar el fichero.
 */
function downloadImage(string $url, string $imagesDir, string $baseUrl, string $fallbackBase): array
{
    $result = downloadFile($url, $imagesDir, $fallbackBase, 30, $baseUrl);
    if ($result['error'] !== null) {
        return $result;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($result['mime'], $allowedMimes, true)) {
        @unlink($result['localPath']);
        $result['error'] = 'MIME de imagen no válido: ' . $result['mime'];
        return $result;
    }

    return $result;
}

/**
 * Descarga un audio del feed al directorio /audios/.
 * Verifica que el MIME sea de audio usando resolveAudioExtension().
 */
function downloadAudio(string $url, string $audiosDir, string $baseUrl, string $fallbackBase): array
{
    $result = downloadFile($url, $audiosDir, $fallbackBase, 300, $baseUrl);
    if ($result['error'] !== null) {
        return $result;
    }

    // Verificar MIME usando el nombre del fichero descargado como fallback
    $origName = basename((string) parse_url($url, PHP_URL_PATH));
    $ext      = resolveAudioExtension($result['mime'], $origName);
    if ($ext === null) {
        @unlink($result['localPath']);
        $result['error'] = 'MIME de audio no válido: ' . $result['mime'];
        return $result;
    }

    return $result;
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
    echo '<!doctype html><html lang="es"><head>' . "\n";
    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>Importando feed RSS</title>' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/admin-common.css">' . "\n";
    echo '</head><body>' . "\n";

    // Barra de navegación
    $currentAdminPage = 'import';
    if (is_file($adminNavPath)) {
        require $adminNavPath;
    }

    echo '<div class="admin-wrap"><main class="card">' . "\n";
    echo '<h1>Importando feed RSS</h1>' . "\n";
    flush();

    // --- 1. Parsear feed ---
    streamLine('<p>Descargando y parseando el feed…</p>');

    $previewResult = loadFeedPreview($feedUrl);
    if ($previewResult['error'] !== '') {
        streamLine('<div class="error">' . esc($previewResult['error']) . '</div>');
        streamLine('<div class="actions"><a class="btn" href="import_feed.php">Volver</a></div>');
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

    streamLine('<p>Feed parseado: <strong>' . $total . ' episodios</strong> seleccionados para importar.</p>');

    // --- 2. Conexión BD ---
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $baseUrl   = resolveBaseUrl($pdo);
    $imagesDir = dirname(__DIR__) . '/images';
    $audiosDir = dirname(__DIR__) . '/audios';

    // --- 3. Actualizar / insertar metadatos del podcast ---
    // 'link' excluido: la URL principal siempre se toma del host actual, nunca del feed externo.
    $allowedMetaFields = ['title', 'description', 'language', 'author',
                          'owner_name', 'owner_email', 'category', 'explicit',
                          'image_url', 'copyright', 'itunes_type'];
    $fieldsToUpdate = array_values(array_intersect($overwriteFields, $allowedMetaFields));

    $existingPodcastId = $pdo->query('SELECT id FROM podcast LIMIT 1')->fetchColumn();

    if ($existingPodcastId === false) {
        // Primera importación: la tabla podcast está vacía → INSERT con todos los campos del feed.
        // Se ignora la selección de overwrite_fields porque no hay datos que proteger.
        if ($podcastMeta['image_url'] !== '') {
            streamLine('<p>Descargando imagen del podcast…</p>');
            $imgResult = downloadImage($podcastMeta['image_url'], $imagesDir, $baseUrl, 'podcast-cover');
            if ($imgResult['error'] !== null) {
                streamLine('<p><em>⚠ No se pudo descargar la imagen del podcast: ' . esc($imgResult['error']) . '</em></p>');
            } else {
                $podcastMeta['image_url'] = $imgResult['localUrl'];
                streamLine('<p>✓ Imagen del podcast descargada.</p>');
            }
        }

        streamLine('<p>Creando datos del podcast desde el feed (primera importación)…</p>');
        $pdo->prepare(
            'INSERT INTO podcast
             (title, description, link, language, author, owner_name, owner_email,
              category, explicit, image_url, copyright, itunes_type)
             VALUES
             (:title, :description, :link, :language, :author, :owner_name, :owner_email,
              :category, :explicit, :image_url, :copyright, :itunes_type)'
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
        streamLine('<p>✓ Datos del podcast creados correctamente.</p>');

    } elseif (!empty($fieldsToUpdate)) {
        // Fila existente: actualizar solo los campos seleccionados por el usuario.
        if (in_array('image_url', $fieldsToUpdate, true) && $podcastMeta['image_url'] !== '') {
            streamLine('<p>Descargando imagen del podcast…</p>');
            $imgResult = downloadImage($podcastMeta['image_url'], $imagesDir, $baseUrl, 'podcast-cover');
            if ($imgResult['error'] !== null) {
                streamLine('<p><em>⚠ No se pudo descargar la imagen del podcast: ' . esc($imgResult['error']) . '</em></p>');
            } else {
                $podcastMeta['image_url'] = $imgResult['localUrl'];
                streamLine('<p>✓ Imagen del podcast descargada.</p>');
            }
        }

        streamLine('<p>Actualizando metadatos del podcast (' . count($fieldsToUpdate) . ' campos)…</p>');
        $setClauses = [];
        $params     = [':id' => (int) $existingPodcastId];
        foreach ($fieldsToUpdate as $field) {
            $setClauses[]        = $field . ' = :' . $field;
            $params[':' . $field] = $podcastMeta[$field];
        }
        $pdo->prepare('UPDATE podcast SET ' . implode(', ', $setClauses) . ' WHERE id = :id')->execute($params);
        streamLine('<p>✓ Metadatos del podcast actualizados.</p>');

    } else {
        streamLine('<p><em>ℹ Metadatos del podcast: ningún campo seleccionado — se omite la actualización.</em></p>');
    }

    // --- 5. Bucle de episodios ---
    $imported = 0;
    $skipped  = 0;
    $errors   = 0;

    $checkGuidStmt = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE guid = :guid LIMIT 1');
    $insertStmt    = $pdo->prepare(
        'INSERT INTO episodes
         (guid, title, description, link, pub_date, audio_url, audio_mime_type, audio_size_bytes,
          duration, explicit, season_number, episode_number, episode_type, image_url, author, status, updated_at)
         VALUES
         (:guid, :title, :description, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes,
          :duration, :explicit, :season_number, :episode_number, :episode_type, :image_url, :author, :status, datetime(\'now\'))'
    );

    streamLine('<hr><h2>Episodios</h2>');
    streamLine('<ul style="list-style:none;padding:0;margin:0">');

    foreach ($episodes as $i => $ep) {
        $num      = $i + 1;
        $epTitle  = $ep['title'] !== '' ? $ep['title'] : '(sin título)';
        $titleEsc = esc($epTitle);

        // Comprobar duplicado por GUID
        if ($skipExisting) {
            $checkGuidStmt->execute([':guid' => $ep['guid']]);
            if ((int) $checkGuidStmt->fetchColumn() > 0) {
                $skipped++;
                streamLine('<li style="padding:.2rem 0">⏭ [' . $num . '/' . $total . '] ' . $titleEsc . ' — <em>saltado (GUID ya existe)</em></li>');
                continue;
            }
        }

        // Sin enclosure de audio: saltar
        if ($ep['audio_url'] === '') {
            $errors++;
            streamLine('<li style="padding:.2rem 0;color:var(--error,#c00)">✗ [' . $num . '/' . $total . '] ' . $titleEsc . ' — <em>sin enclosure de audio</em></li>');
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
        streamLine('<li style="padding:.2rem 0">⬇ [' . $num . '/' . $total . '] ' . $titleEsc . ' — descargando audio…');
        $audioResult = downloadAudio($ep['audio_url'], $audiosDir, $baseUrl, 'audio-ep-' . $num);
        if ($audioResult['error'] !== null) {
            $errors++;
            echo ' <strong style="color:var(--error,#c00)">Error: ' . esc($audioResult['error']) . '</strong></li>' . "\n";
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
                ':guid'             => $guid,
                ':title'            => $ep['title'],
                ':description'      => $ep['description'],
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
            echo ' <strong style="color:var(--error,#c00)">Error BD: ' . esc($e->getMessage()) . '</strong></li>' . "\n";
            echo str_repeat(' ', 1024) . "\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }

    streamLine('</ul><hr>');

    // --- 6. Regenerar feed.xml, sitemap.xml y caché ---
    streamLine('<p>Regenerando feed.xml y sitemap.xml…</p>');
    try {
        writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
        writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
        streamLine('<p>✓ feed.xml y sitemap.xml regenerados.</p>');
    } catch (Throwable $e) {
        streamLine('<p><em>⚠ No se pudo regenerar feed/sitemap: ' . esc($e->getMessage()) . '</em></p>');
    }

    clearWebCache();
    streamLine('<p>✓ Caché borrada.</p>');

    // --- 7. Resumen final ---
    streamLine('<div class="notice"><strong>Importación completada:</strong> '
        . $imported . ' importados, '
        . $skipped  . ' saltados, '
        . $errors   . ' errores.</div>');

    streamLine('<div class="actions" style="margin-top:1rem">');
    streamLine('<a class="btn" href="episodes_management.php">Ver episodios</a>');
    streamLine('<a class="btn" href="import_feed.php">Importar otro feed</a>');
    streamLine('</div>');

    streamLine('</main></div></body></html>');
    exit;
}
