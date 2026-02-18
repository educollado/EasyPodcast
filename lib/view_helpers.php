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
