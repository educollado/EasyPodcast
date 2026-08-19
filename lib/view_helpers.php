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
 * Etiquetas HTML permitidas en contenido enriquecido.
 *
 * @return array<string, array<int, string>>
 */
function richHtmlAllowedTags(): array
{
    return [
        'a' => ['href', 'title', 'target', 'rel'],
        'audio' => ['src', 'controls', 'preload'],
        'blockquote' => [],
        'br' => [],
        'code' => [],
        'del' => [],
        'div' => [],
        'em' => [],
        'figcaption' => [],
        'figure' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'hr' => [],
        'i' => [],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'class'],
        'li' => [],
        'mark' => [],
        'ol' => ['start'],
        'p' => [],
        'pre' => [],
        's' => [],
        'source' => ['src', 'type'],
        'span' => [],
        'strong' => [],
        'sub' => [],
        'sup' => [],
        'strike' => [],
        'table' => [],
        'tbody' => [],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'thead' => [],
        'tr' => [],
        'u' => [],
        'ul' => [],
        'video' => ['src', 'controls', 'preload', 'poster', 'width', 'height', 'muted', 'loop'],
    ];
}

/**
 * Etiquetas cuyo contenido completo debe descartarse.
 *
 * @return array<int, string>
 */
function richHtmlDropContentTags(): array
{
    return [
        'applet',
        'base',
        'button',
        'embed',
        'form',
        'head',
        'html',
        'iframe',
        'input',
        'link',
        'meta',
        'noscript',
        'object',
        'option',
        'script',
        'select',
        'style',
        'textarea',
    ];
}

/**
 * Devuelve true si la IP cae en un rango privado, loopback o reservado.
 */
function isPrivateOrReservedIp(string $ip): bool
{
    $packedIp = @inet_pton($ip);
    if ($packedIp === false) {
        return true;
    }

    $blockedCidrs = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
        '::/128',
        '::1/128',
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:2::/48',
        '2001:10::/28',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    foreach ($blockedCidrs as $cidr) {
        if (ipMatchesCidr($ip, $cidr)) {
            return true;
        }
    }

    return false;
}

/**
 * Comprueba si una IP pertenece a un CIDR concreto.
 */
function ipMatchesCidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = explode('/', $cidr, 2);
    $packedIp = @inet_pton($ip);
    $packedSubnet = @inet_pton($subnet);
    if ($packedIp === false || $packedSubnet === false || strlen($packedIp) !== strlen($packedSubnet)) {
        return false;
    }

    $prefixLength = (int) $bits;
    $fullBytes = intdiv($prefixLength, 8);
    $remainingBits = $prefixLength % 8;

    if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedSubnet, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedSubnet[$fullBytes]) & $mask);
}

/**
 * Limpia una URL embebida en HTML y devuelve null si usa esquemas inseguros.
 *
 * @param array<int, string> $allowedSchemes
 */
function sanitizeRichHtmlUrl(string $url, array $allowedSchemes, bool $allowRelative = true): ?string
{
    $decoded = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = preg_replace('/[\x00-\x1F\x7F\s]+/u', '', $decoded) ?? '';
    if ($decoded === '' || str_starts_with($decoded, '//')) {
        return null;
    }

    if (
        $allowRelative
        && (
            str_starts_with($decoded, '#')
            || str_starts_with($decoded, '/')
            || str_starts_with($decoded, './')
            || str_starts_with($decoded, '../')
            || str_starts_with($decoded, '?')
        )
    ) {
        return $decoded;
    }

    $scheme = strtolower((string) parse_url($decoded, PHP_URL_SCHEME));
    if ($scheme === '' || !in_array($scheme, $allowedSchemes, true)) {
        return null;
    }

    if (in_array($scheme, ['http', 'https'], true) && filter_var($decoded, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    return $decoded;
}

/**
 * Renderiza un subconjunto seguro de Markdown inline.
 *
 * Admite código, negrita, cursiva y enlaces HTTP/HTTPS. Todo el texto y las
 * URL se escapan antes de incorporarse al HTML.
 */
function renderSafeMarkdownInline(string $value): string
{
    $pattern = '~`([^`\r\n]+)`|\*\*([^*\r\n]+)\*\*|(?<!\*)\*([^*\r\n]+)\*(?!\*)|\[([^\]\r\n]+)\]\(([^)\s]+)\)~u';
    $html = '';
    $offset = 0;
    $length = strlen($value);

    while (
        $offset < $length
        && preg_match($pattern, $value, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1
    ) {
        $token = (string) $match[0][0];
        $tokenOffset = (int) $match[0][1];
        $html .= esc(substr($value, $offset, $tokenOffset - $offset));

        if (($match[1][1] ?? -1) >= 0) {
            $html .= '<code>' . esc((string) $match[1][0]) . '</code>';
        } elseif (($match[2][1] ?? -1) >= 0) {
            $html .= '<strong>' . esc((string) $match[2][0]) . '</strong>';
        } elseif (($match[3][1] ?? -1) >= 0) {
            $html .= '<em>' . esc((string) $match[3][0]) . '</em>';
        } else {
            $safeUrl = sanitizeRichHtmlUrl((string) $match[5][0], ['http', 'https'], false);
            if ($safeUrl === null) {
                $html .= esc($token);
            } else {
                $html .= '<a href="' . esc($safeUrl) . '" target="_blank" rel="noopener noreferrer">'
                    . esc((string) $match[4][0]) . '</a>';
            }
        }

        $offset = $tokenOffset + strlen($token);
    }

    return $html . esc(substr($value, $offset));
}

/**
 * Devuelve la versión saneada del contenido HTML enriquecido.
 */
function sanitizeRichHtml(string $value): string
{
    $html = trim($value);
    if ($html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        return nl2br(esc(strip_tags($html)));
    }

    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $wrapperId = 'easypodcast-sanitize-root';
    $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET;
    $loaded = $document->loadHTML(
        '<?xml encoding="utf-8" ?><div id="' . $wrapperId . '">' . $html . '</div>',
        $flags
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);

    if ($loaded === false) {
        return nl2br(esc(strip_tags($html)));
    }

    $root = $document->getElementById($wrapperId);
    if (!$root instanceof DOMElement) {
        return nl2br(esc(strip_tags($html)));
    }

    sanitizeRichHtmlNodeTree($root);

    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $document->saveHTML($child);
    }

    return trim($result);
}

/**
 * Sanea recursivamente un árbol DOM de contenido enriquecido.
 */
function sanitizeRichHtmlNodeTree(DOMNode $parent): void
{
    $allowedTags = richHtmlAllowedTags();
    $dropTags = richHtmlDropContentTags();

    for ($node = $parent->firstChild; $node !== null; $node = $nextNode) {
        $nextNode = $node->nextSibling;

        if ($node instanceof DOMComment) {
            $parent->removeChild($node);
            continue;
        }

        if ($node instanceof DOMText) {
            continue;
        }

        if (!$node instanceof DOMElement) {
            $parent->removeChild($node);
            continue;
        }

        $tagName = strtolower($node->tagName);
        if (in_array($tagName, $dropTags, true)) {
            $parent->removeChild($node);
            continue;
        }

        sanitizeRichHtmlNodeTree($node);

        if (!isset($allowedTags[$tagName])) {
            unwrapDomElement($node);
            continue;
        }

        sanitizeRichHtmlAttributes($node, $allowedTags[$tagName]);
    }
}

/**
 * Reemplaza un elemento por sus hijos ya saneados.
 */
function unwrapDomElement(DOMElement $element): void
{
    $parent = $element->parentNode;
    if (!$parent instanceof DOMNode) {
        return;
    }

    while ($element->firstChild !== null) {
        $parent->insertBefore($element->firstChild, $element);
    }

    $parent->removeChild($element);
}

/**
 * Aplica la whitelist de atributos de un elemento HTML permitido.
 *
 * @param array<int, string> $allowedAttributes
 */
function sanitizeRichHtmlAttributes(DOMElement $element, array $allowedAttributes): void
{
    $tagName = strtolower($element->tagName);
    $imageAlignmentClass = $tagName === 'img'
        ? richHtmlImageAlignmentClass($element->getAttribute('style'))
        : null;
    $attributes = [];
    foreach ($element->attributes as $attribute) {
        $attributes[] = $attribute->name;
    }

    foreach ($attributes as $name) {
        $lowerName = strtolower($name);
        if (
            str_starts_with($lowerName, 'on')
            || $lowerName === 'style'
            || !in_array($lowerName, $allowedAttributes, true)
        ) {
            $element->removeAttribute($name);
            continue;
        }

        $value = trim($element->getAttribute($name));
        if ($value === '' && !in_array($lowerName, ['controls', 'muted', 'loop'], true)) {
            $element->removeAttribute($name);
            continue;
        }

        if ($lowerName === 'href') {
            $safeUrl = sanitizeRichHtmlUrl($value, ['http', 'https', 'mailto', 'tel']);
            if ($safeUrl === null) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, $safeUrl);
            }
            continue;
        }

        if (in_array($lowerName, ['src', 'poster'], true)) {
            $safeUrl = sanitizeRichHtmlUrl($value, ['http', 'https']);
            if ($safeUrl === null) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, $safeUrl);
            }
            continue;
        }

        if ($lowerName === 'target') {
            if ($value !== '_blank') {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, '_blank');
            }
            continue;
        }

        if ($lowerName === 'rel') {
            continue;
        }

        if ($lowerName === 'class' && $tagName === 'img') {
            $safeClasses = array_values(array_intersect(
                preg_split('/\s+/', $value) ?: [],
                ['rich-img-float-left', 'rich-img-float-right']
            ));
            if ($safeClasses === []) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, $safeClasses[0]);
            }
            continue;
        }

        if (in_array($lowerName, ['width', 'height', 'colspan', 'rowspan', 'start'], true)) {
            if (!ctype_digit($value)) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, (string) (int) $value);
            }
            continue;
        }

        if ($lowerName === 'scope') {
            $safeScope = strtolower($value);
            if (!in_array($safeScope, ['row', 'col', 'rowgroup', 'colgroup'], true)) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, $safeScope);
            }
            continue;
        }

        if ($lowerName === 'preload') {
            $safePreload = strtolower($value);
            if (!in_array($safePreload, ['auto', 'metadata', 'none'], true)) {
                $element->removeAttribute($name);
            } else {
                $element->setAttribute($name, $safePreload);
            }
            continue;
        }

        if ($lowerName === 'type') {
            if (!preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $value)) {
                $element->removeAttribute($name);
            }
            continue;
        }

        if (in_array($lowerName, ['controls', 'muted', 'loop'], true)) {
            $element->setAttribute($name, $lowerName);
            continue;
        }

        $element->setAttribute($name, $value);
    }

    if ($tagName === 'a' && $element->getAttribute('target') === '_blank') {
        $element->setAttribute('rel', 'noopener noreferrer');
    }

    if ($imageAlignmentClass !== null) {
        $element->setAttribute('class', $imageAlignmentClass);
    }

    if (in_array($tagName, ['audio', 'video'], true) && !$element->hasAttribute('controls')) {
        $element->setAttribute('controls', 'controls');
    }
}

/**
 * Traduce únicamente la alineación inline del editor a una clase CSS segura.
 */
function richHtmlImageAlignmentClass(string $style): ?string
{
    foreach (explode(';', $style) as $declaration) {
        $parts = explode(':', $declaration, 2);
        if (count($parts) !== 2 || strtolower(trim($parts[0])) !== 'float') {
            continue;
        }

        return match (strtolower(trim($parts[1]))) {
            'left' => 'rich-img-float-left',
            'right' => 'rich-img-float-right',
            default => null,
        };
    }

    return null;
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
