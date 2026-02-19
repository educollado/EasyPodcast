<?php

declare(strict_types=1);

// Base absoluta para etiquetas SEO (canonical, OG, JSON-LD).
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

// Convierte rutas relativas en URLs absolutas para OG/JSON-LD.
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

// Limpia y recorta texto para meta description.
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
