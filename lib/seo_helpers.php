<?php

declare(strict_types=1);

/**
 * Base absoluta para etiquetas SEO (canonical, OG, JSON-LD).
 * Prefiere la URL de podcast.link; cae en el host actual como fallback.
 */
function resolveSeoBaseUrl(?string $podcastLink): string
{
    $raw = trim((string) $podcastLink);
    if ($raw !== '') {
        $parts = parse_url($raw);
        if (is_array($parts) && !empty($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            $host = (string) $parts['host'];
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            return $scheme . '://' . $host . $port;
        }
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

/**
 * Convierte rutas relativas en URLs absolutas para OG/JSON-LD.
 * Si $value ya es una URL absoluta, la devuelve sin modificar.
 */
function toAbsoluteSeoUrl(string $value, string $baseUrl): string
{
    $raw = trim($value);
    if ($raw === '') {
        return rtrim($baseUrl, '/');
    }

    $parts = parse_url($raw);
    if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        return $raw;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($raw, '/');
}

/** Construye una ruta pública con el prefijo del podcast cuando tiene directorio. */
function podcastSeoPath(array $podcast, string $path = ''): string
{
    $slug = (($podcast['_multipodcast_enabled'] ?? true) === true)
        ? trim((string) ($podcast['slug'] ?? ''))
        : '';
    $prefix = $slug !== '' ? '/' . rawurlencode($slug) : '';
    $suffix = '/' . ltrim($path, '/');
    return $prefix . $suffix;
}

/**
 * Convierte una URL de perfil de Mastodon al formato fediverse:creator.
 * Ejemplo: https://mastodon.social/@ecollado → @ecollado@mastodon.social
 * Devuelve cadena vacía si la URL no es un perfil Mastodon válido.
 */
function mastodonUrlToFediverseHandle(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
        return '';
    }
    $path = ltrim((string) $parts['path'], '/');
    // El path debe comenzar con '@' (perfil de usuario).
    if (!str_starts_with($path, '@')) {
        return '';
    }
    // Toma solo el primer segmento del path (@usuario), ignorando sub-rutas.
    $user = explode('/', $path)[0];
    return $user . '@' . (string) $parts['host'];
}

/**
 * Limpia y recorta texto para meta description.
 * Usa mb_* cuando está disponible para respetar caracteres multibyte.
 */
function compactMetaText(string $value, int $maxChars = 160): string
{
    $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    if ($clean === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean, 'UTF-8') <= $maxChars) {
            return $clean;
        }
        return rtrim(mb_substr($clean, 0, $maxChars, 'UTF-8')) . '...';
    }

    if (strlen($clean) <= $maxChars) {
        return $clean;
    }

    return rtrim(substr($clean, 0, $maxChars)) . '...';
}
