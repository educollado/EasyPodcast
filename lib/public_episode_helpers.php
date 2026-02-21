<?php

declare(strict_types=1);

require_once __DIR__ . '/view_helpers.php';

/**
 * Construye la ruta amigable /YYYY/MM/slug usada por .htaccess y episode.php.
 * Si pubDate no es parseable, usa la fecha actual.
 */
function buildEpisodePath(string $pubDate, string $title): string
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
 * Devuelve el enlace del episodio: el almacenado en BD si existe, o uno generado desde fecha+título.
 */
function resolveEpisodeHref(?string $storedLink, string $pubDate, string $title): string
{
    $link = trim((string) $storedLink);
    if ($link !== '') {
        return $link;
    }

    return buildEpisodePath($pubDate, $title);
}

/**
 * Extrae el slug desde una URL guardada en formato /YYYY/MM/slug.
 * Devuelve null si el enlace está vacío o no coincide con el patrón.
 */
function slugFromEpisodeLink(?string $link): ?string
{
    $raw = trim((string) $link);
    if ($raw === '') {
        return null;
    }

    $path = (string) parse_url($raw, PHP_URL_PATH);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^/[0-9]{4}/[0-9]{2}/([a-z0-9-]+)/?$#', $path, $matches) === 1) {
        return $matches[1];
    }

    return null;
}
