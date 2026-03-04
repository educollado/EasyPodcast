<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers generales de episodio: nombres de fichero, fechas, slugs y rutas
// ---------------------------------------------------------------------------

require_once __DIR__ . '/view_helpers.php';

/**
 * Genera nombres de fichero seguros y deterministas con timestamp + sufijo aleatorio.
 * Elimina caracteres no ASCII del nombre original para evitar path traversal.
 */
function buildSafeFileName(string $originalName, string $fallbackBase, string $extension): string
{
    $base = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)));
    $base = trim($base, '-');
    if ($base === '') {
        $base = $fallbackBase;
    }

    return $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
}

/**
 * Resuelve formatos de audio aceptados por MIME (con fallback por extensión).
 * Devuelve la extensión normalizada o null si el formato no está permitido.
 */
function resolveAudioExtension(string $mimeType, string $originalName): ?string
{
    $allowedAudios = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/x-mpeg' => 'mp3',
        'audio/x-mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'video/mp4' => 'm4a',
        'application/mp4' => 'm4a',
        'application/x-mpegurl' => 'mp3',
        'audio/aac' => 'aac',
        'audio/x-aac' => 'aac',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/vorbis' => 'ogg',
        'audio/wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/x-wav' => 'wav',
        'application/wav' => 'wav',
        'audio/webm' => 'webm',
    ];

    if (isset($allowedAudios[$mimeType])) {
        return $allowedAudios[$mimeType];
    }

    // Fallback por extensión porque algunos entornos reportan MIME poco fiables.
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = [
        'mp3' => 'mp3',
        'm4a' => 'm4a',
        'aac' => 'aac',
        'ogg' => 'ogg',
        'wav' => 'wav',
        'webm' => 'webm',
    ];
    if (isset($allowedExtensions[$extension])) {
        return $allowedExtensions[$extension];
    }

    return null;
}

/**
 * Normaliza varios formatos de fecha UI/API a datetime SQL (Y-m-d H:i:s).
 * Acepta datetime-local HTML5 (Y-m-d\TH:i), SQL y cadenas libres.
 * Devuelve null si el valor está vacío o no es parseable.
 */
function normalizeDateTime(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $formats = ['Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * Convierte datetime almacenado al formato de <input type="datetime-local"> (Y-m-d\TH:i).
 * Devuelve cadena vacía si el valor es nulo, vacío o no parseable.
 */
function formatDateTimeLocal(?string $value): string
{
    $normalized = normalizeDateTime($value);
    if ($normalized === null) {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized);
    if (!$dt instanceof DateTimeImmutable) {
        return '';
    }

    return $dt->format('Y-m-d\\TH:i');
}

/**
 * Genera un GUID único para el episodio cuando el usuario no lo proporciona.
 * Formato: ep-YYYYmmddHHiiss-<8 hex aleatorios>.
 */
function generateGuid(): string
{
    return 'ep-' . date('YmdHis') . '-' . bin2hex(random_bytes(8));
}

/**
 * Construye la URL pública del episodio en formato /YYYY/MM/slug.
 * Si pubDate no es válida, usa la fecha actual como fallback.
 */
function buildEpisodePublicLink(string $baseUrl, ?string $pubDate, string $title): string
{
    $normalized = normalizeDateTime($pubDate);
    $ts = $normalized !== null ? strtotime($normalized) : false;
    if ($ts === false) {
        $ts = time();
    }

    return rtrim($baseUrl, '/') . '/'
        . date('Y', $ts) . '/'
        . date('m', $ts) . '/'
        . slugify($title);
}

/**
 * Resuelve la ruta local a /audios/<fichero> partiendo de una URL pública.
 * Se usa para poder reescribir metadatos ID3 sobre el fichero físico existente.
 * Devuelve null si la URL no apunta a un fichero local en /audios/ o no existe.
 */
function resolveLocalAudioPathFromUrl(string $audioUrl): ?string
{
    $path = parse_url(trim($audioUrl), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    if (!preg_match('#/audios/([^/]+)$#', $path, $matches)) {
        return null;
    }

    $fileName = basename((string) $matches[1]);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }

    $localPath = __DIR__ . '/../audios/' . $fileName;
    if (!is_file($localPath)) {
        return null;
    }

    return $localPath;
}

/**
 * Resuelve la ruta local de un fichero de imagen a partir de su URL relativa o absoluta.
 * Verifica que la ruta resuelta no escape de la raíz del proyecto (protección path traversal).
 * Devuelve null si la ruta no existe como fichero o queda fuera del proyecto.
 */
function resolveLocalImagePathFromUrl(string $imageUrl): ?string
{
    $raw = trim($imageUrl);
    if ($raw === '') {
        return null;
    }

    $parsedPath = (string) parse_url($raw, PHP_URL_PATH);
    $candidate = $parsedPath !== '' ? $parsedPath : $raw;

    if ($candidate === '') {
        return null;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }

    if ($candidate[0] === '/') {
        $candidate = $projectRoot . $candidate;
    } else {
        $candidate = $projectRoot . '/' . $candidate;
    }

    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return null;
    }

    if (strpos($real, $projectRoot . DIRECTORY_SEPARATOR) !== 0 && $real !== $projectRoot) {
        return null;
    }

    return $real;
}
