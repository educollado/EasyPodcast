<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers de salida para vistas publicas/admin
// ---------------------------------------------------------------------------

// Helper básico de escape HTML para salida segura.
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Convierte URLs en enlaces sin permitir HTML arbitrario.
function renderTextWithLinks(string $value): string
{
    // Split conservando las URL para tratarlas de forma segura.
    $parts = preg_split('~(https?://[^\s<>"\']+)~iu', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return nl2br(esc($value));
    }

    $html = '';
    foreach ($parts as $index => $part) {
        if ($part === '') {
            continue;
        }

        if ($index % 2 === 1) {
            // Recorta puntuacion final habitual para no romper enlaces.
            $url = rtrim($part, '.,;:!?)');
            $suffix = substr($part, strlen($url));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $safeUrl = esc($url);
                $html .= '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>';
                $html .= esc($suffix);
                continue;
            }
        }

        $html .= esc($part);
    }

    return nl2br($html);
}

// Construye slugs seguros para URL desde texto.
function slugify(string $value): string
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

// Fecha de publicación legible.
function formatPublishedDate(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return date('d/m/Y H:i', $ts);
}

// Convierte URL de imagen local a ruta de disco dentro del proyecto.
function resolveLocalImagePath(string $imageUrl): ?string
{
    $raw = trim($imageUrl);
    if ($raw === '') {
        return null;
    }

    $path = (string) parse_url($raw, PHP_URL_PATH);
    if ($path === '') {
        $path = $raw;
    }
    if ($path === '') {
        return null;
    }

    $projectRoot = dirname(__DIR__);
    $candidate = $path[0] === '/' ? $projectRoot . $path : $projectRoot . '/' . ltrim($path, '/');
    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return null;
    }

    $realProjectRoot = realpath($projectRoot);
    if ($realProjectRoot === false) {
        return null;
    }
    if (strpos($real, $realProjectRoot . DIRECTORY_SEPARATOR) !== 0 && $real !== $realProjectRoot) {
        return null;
    }

    return $real;
}

// Genera el nombre/URL de variante con sufijo de tamaño en /images/generated/.
// Ejemplo: /images/foo.jpg -> /images/generated/foo-144x144.jpg
function buildSizedImageUrl(string $sourceUrl, int $size): ?string
{
    $raw = trim($sourceUrl);
    if ($raw === '') {
        return null;
    }

    $parts = parse_url($raw);
    $path = (string) ($parts['path'] ?? '');
    if ($path === '') {
        return null;
    }

    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $base = pathinfo($path, PATHINFO_FILENAME);
    if ($ext === '' || $base === '') {
        return null;
    }

    $variantPath = '/images/generated/' . $base . '-' . $size . 'x' . $size . '.' . $ext;

    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    $host = isset($parts['host']) ? (string) $parts['host'] : '';
    $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
    $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';

    if ($host === '') {
        return $variantPath . $query . $fragment;
    }

    $auth = '';
    if (isset($parts['user'])) {
        $auth = (string) $parts['user'];
        if (isset($parts['pass'])) {
            $auth .= ':' . (string) $parts['pass'];
        }
        $auth .= '@';
    }

    return $scheme . '://' . $auth . $host . $port . $variantPath . $query . $fragment;
}

// Crea (si no existe) y devuelve una variante cuadrada local de la imagen.
function ensureSquareImageVariant(string $sourceUrl, int $size): string
{
    $raw = trim($sourceUrl);
    if ($raw === '' || $size <= 0) {
        return $raw;
    }

    $variantUrl = buildSizedImageUrl($raw, $size);
    if ($variantUrl === null) {
        return $raw;
    }

    $variantLocalPath = resolveLocalImagePath($variantUrl);
    if ($variantLocalPath !== null && is_file($variantLocalPath)) {
        return $variantUrl;
    }

    $sourceLocalPath = resolveLocalImagePath($raw);
    if ($sourceLocalPath === null || !function_exists('imagecreatefromstring')) {
        return $raw;
    }

    $sourceBytes = @file_get_contents($sourceLocalPath);
    if (!is_string($sourceBytes) || $sourceBytes === '') {
        return $raw;
    }

    $src = @imagecreatefromstring($sourceBytes);
    if ($src === false) {
        return $raw;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $crop = min($srcW, $srcH);
    $srcX = (int) floor(($srcW - $crop) / 2);
    $srcY = (int) floor(($srcH - $crop) / 2);

    $dst = imagecreatetruecolor($size, $size);
    if ($dst === false) {
        imagedestroy($src);
        return $raw;
    }

    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);

    if (!imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $size, $size, $crop, $crop)) {
        imagedestroy($dst);
        imagedestroy($src);
        return $raw;
    }

    $variantPath = (string) parse_url($variantUrl, PHP_URL_PATH);
    $projectRoot = dirname(__DIR__);
    $targetFile = $variantPath !== '' ? $projectRoot . $variantPath : '';
    $targetDir = $targetFile !== '' ? dirname($targetFile) : '';
    if ($targetFile === '' || $targetDir === '' || (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir))) {
        imagedestroy($dst);
        imagedestroy($src);
        return $raw;
    }

    $ext = strtolower((string) pathinfo($targetFile, PATHINFO_EXTENSION));
    $written = false;
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('imagejpeg')) {
        $written = @imagejpeg($dst, $targetFile, 90);
    } elseif ($ext === 'png' && function_exists('imagepng')) {
        $written = @imagepng($dst, $targetFile, 7);
    } elseif ($ext === 'gif' && function_exists('imagegif')) {
        $written = @imagegif($dst, $targetFile);
    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
        $written = @imagewebp($dst, $targetFile, 90);
    }

    imagedestroy($dst);
    imagedestroy($src);

    if (!$written || !is_file($targetFile)) {
        return $raw;
    }

    return $variantUrl;
}

// Construye fuentes responsive (src/srcset) para variantes cuadradas.
function buildResponsiveSquareImageSources(string $sourceUrl, array $preferredSizes): array
{
    $raw = trim($sourceUrl);
    if ($raw === '') {
        return ['src' => '', 'srcset' => ''];
    }

    $sizes = [];
    foreach ($preferredSizes as $size) {
        $intSize = (int) $size;
        if ($intSize > 0) {
            $sizes[$intSize] = true;
        }
    }
    if (!$sizes) {
        return ['src' => $raw, 'srcset' => ''];
    }
    $sizes = array_keys($sizes);
    sort($sizes, SORT_NUMERIC);

    $maxAvailable = 0;
    $sourceLocalPath = resolveLocalImagePath($raw);
    if ($sourceLocalPath !== null && function_exists('getimagesize')) {
        $meta = @getimagesize($sourceLocalPath);
        if (is_array($meta) && isset($meta[0], $meta[1])) {
            $maxAvailable = (int) min((int) $meta[0], (int) $meta[1]);
        }
    }

    $entries = [];
    $addedUrls = [];
    $lastUrl = $raw;
    foreach ($sizes as $size) {
        if ($maxAvailable > 0 && $size > $maxAvailable) {
            continue;
        }

        $variantUrl = ensureSquareImageVariant($raw, $size);
        if ($variantUrl === '' || isset($addedUrls[$variantUrl])) {
            continue;
        }

        // Evita repetir la imagen original como "variante" cuando no se pudo generar.
        if ($variantUrl === $raw && $size !== $maxAvailable) {
            continue;
        }

        $entries[] = $variantUrl . ' ' . $size . 'w';
        $addedUrls[$variantUrl] = true;
        $lastUrl = $variantUrl;
    }

    if (!$entries) {
        return ['src' => $raw, 'srcset' => ''];
    }

    return [
        'src' => $lastUrl,
        'srcset' => implode(', ', $entries),
    ];
}
