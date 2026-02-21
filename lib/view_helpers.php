<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers de salida para vistas publicas/admin
// ---------------------------------------------------------------------------

/**
 * Escapa HTML para salida segura en plantillas.
 * Equivale a htmlspecialchars con ENT_QUOTES + UTF-8.
 */
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Convierte URLs en texto a elementos <a> seguros, escapando todo lo demás.
 * Las partes sin URL se escapan con esc(); no se permite HTML arbitrario.
 */
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

/**
 * Aplica formato Markdown inline (negrita, cursiva, enlaces) a una línea de texto.
 * Las partes sin formato se escapan con esc(); el HTML generado es seguro.
 */
function renderMarkdownInline(string $text): string
{
    $parts = preg_split(
        '/(\*\*[^*]+\*\*|\*[^*]+\*|\[[^\]]+\]\(https?:\/\/[^\s)]+\))/u',
        $text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    if ($parts === false) {
        return esc($text);
    }

    $html = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $html .= esc($part);
        } elseif (str_starts_with($part, '**')) {
            $html .= '<strong>' . esc(substr($part, 2, -2)) . '</strong>';
        } elseif (str_starts_with($part, '*')) {
            $html .= '<em>' . esc(substr($part, 1, -1)) . '</em>';
        } elseif (str_starts_with($part, '[')) {
            if (preg_match('/^\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)$/', $part, $m)
                && filter_var($m[2], FILTER_VALIDATE_URL) !== false) {
                $html .= '<a href="' . esc($m[2]) . '" target="_blank" rel="noopener noreferrer">' . esc($m[1]) . '</a>';
            } else {
                $html .= esc($part);
            }
        } else {
            $html .= esc($part);
        }
    }

    return $html;
}

/**
 * Convierte texto Markdown sencillo a HTML seguro.
 * Soporta: encabezados (#/##/###), listas (-/* y 1.), negrita, cursiva, enlaces y párrafos.
 * No se permite HTML arbitrario; todo el texto libre se escapa con esc().
 */
function renderMarkdown(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $rawBlocks = preg_split('/\n{2,}/', $value);
    if ($rawBlocks === false) {
        return '<p>' . renderMarkdownInline($value) . '</p>';
    }

    $html = '';
    foreach ($rawBlocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        $lines = explode("\n", $block);

        // Encabezado (bloque de una sola línea que empieza por #)
        if (count($lines) === 1 && preg_match('/^(#{1,3})\s+(.+)$/', $lines[0], $m)) {
            $level = strlen($m[1]);
            $html .= '<h' . $level . '>' . renderMarkdownInline(trim($m[2])) . '</h' . $level . ">\n";
            continue;
        }

        // Lista no ordenada
        if (preg_match('/^[-*]\s/', $lines[0])) {
            $html .= "<ul>\n";
            foreach ($lines as $line) {
                if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                    $html .= '<li>' . renderMarkdownInline(trim($m[1])) . "</li>\n";
                }
            }
            $html .= "</ul>\n";
            continue;
        }

        // Lista ordenada
        if (preg_match('/^\d+\.\s/', $lines[0])) {
            $html .= "<ol>\n";
            foreach ($lines as $line) {
                if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
                    $html .= '<li>' . renderMarkdownInline(trim($m[1])) . "</li>\n";
                }
            }
            $html .= "</ol>\n";
            continue;
        }

        // Párrafo normal: saltos de línea simples → <br>
        $lineHtml = array_map('renderMarkdownInline', $lines);
        $html .= '<p>' . implode("<br>\n", $lineHtml) . "</p>\n";
    }

    return $html !== '' ? $html : '<p></p>';
}

/**
 * Genera un extracto de texto compacto e indica si fue recortado.
 *
 * @return array{text: string, truncated: bool}
 */
function firstChars(string $value, int $maxChars): array
{
    $clean = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($clean === '') {
        return ['text' => '', 'truncated' => false];
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean, 'UTF-8') <= $maxChars) {
            return ['text' => $clean, 'truncated' => false];
        }
        return ['text' => rtrim(mb_substr($clean, 0, $maxChars, 'UTF-8')), 'truncated' => true];
    }

    if (strlen($clean) <= $maxChars) {
        return ['text' => $clean, 'truncated' => false];
    }

    return ['text' => rtrim(substr($clean, 0, $maxChars)), 'truncated' => true];
}

/**
 * Devuelve el tamaño en bytes formateado en unidades humanas (B, KB, MB...).
 * Devuelve cadena vacía si el valor es 0 o negativo.
 */
function formatBytes(mixed $bytes): string
{
    $size = (int) $bytes;
    if ($size <= 0) {
        return '';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $value = (float) $size;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $index === 0 ? 0 : 2, ',', '.') . ' ' . $units[$index];
}

/**
 * Convierte texto a slug seguro para URL.
 * Transliteral caracteres no ASCII con iconv cuando está disponible.
 */
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

/**
 * Devuelve la fecha de publicación en formato d/m/Y H:i legible para el usuario.
 * Devuelve cadena vacía si el valor está vacío o no es parseable.
 */
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

/**
 * Convierte URL de imagen local a ruta absoluta de disco dentro del proyecto.
 * Verifica que la ruta resuelta no escape de la raíz del proyecto.
 * Devuelve null si no existe el fichero o la ruta es inválida.
 */
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

/**
 * Genera la URL de una variante cuadrada con sufijo de tamaño en /images/generated/.
 * Ejemplo: /images/foo.jpg -> /images/generated/foo-144x144.jpg
 * Devuelve null si la URL fuente no tiene path o extensión válidos.
 */
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

/**
 * Crea (si no existe) y devuelve una variante cuadrada redimensionada de la imagen.
 * Si no se puede generar la variante, devuelve la URL original sin modificar.
 * Requiere la extensión GD de PHP.
 */
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

/**
 * Construye src y srcset responsive para variantes cuadradas generadas.
 * Omite tamaños superiores a la resolución original de la imagen.
 *
 * @param int[] $preferredSizes Lista de anchos deseados en píxeles.
 * @return array{src: string, srcset: string}
 */
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
