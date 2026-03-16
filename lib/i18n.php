<?php

declare(strict_types=1);

/**
 * Sistema de internacionalización (i18n) para EasyPodcast.
 *
 * Flujo:
 *   1. Llamar i18n_load('es_ES') una vez al inicio (canonical_redirect.php).
 *   2. Usar __('cadena') o __('plantilla %s', $var) en código PHP.
 *
 * El archivo .po usa el español como msgid; msgstr vacío → se usa el propio msgid.
 * Para añadir otro idioma: crear locale/en_US.po con los msgid en español y
 * los msgstr en inglés, y cambiar la llamada a i18n_load('en_US').
 */

$GLOBALS['_i18n'] = [];
$GLOBALS['_i18n_locale'] = 'es_ES';

function i18n_load(string $locale): void
{
    $locale = preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale) ? $locale : 'es_ES';
    $GLOBALS['_i18n_locale'] = $locale;
    $GLOBALS['_i18n'] = [];

    $file = __DIR__ . '/../locale/' . $locale . '.po';
    if (file_exists($file)) {
        $GLOBALS['_i18n'] = i18n_parse_po($file);
    }
}

function i18n_current_locale(): string
{
    return (string) ($GLOBALS['_i18n_locale'] ?? 'es_ES');
}

function i18n_html_lang(): string
{
    return str_replace('_', '-', i18n_current_locale());
}

/**
 * Devuelve la traducción del msgid dado.
 * Si el msgstr está vacío o no existe, devuelve el propio msgid.
 * Acepta argumentos adicionales para vsprintf.
 */
function __(string $msgid, ...$args): string
{
    $str = $GLOBALS['_i18n'][$msgid] ?? $msgid;
    return $args ? vsprintf($str, $args) : $str;
}

/** Parsea un fichero .po y devuelve un mapa msgid → msgstr (solo entradas no vacías). */
function i18n_parse_po(string $file): array
{
    $translations = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $translations;
    }
    $currentMsgid = null;
    foreach ($lines as $line) {
        $line = trim($line);
        // Ignorar comentarios y líneas vacías
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'msgid ')) {
            $currentMsgid = i18n_unquote(substr($line, 6));
        } elseif (str_starts_with($line, 'msgstr ') && $currentMsgid !== null) {
            $msgstr = i18n_unquote(substr($line, 7));
            // Solo registrar si msgstr no está vacío y msgid tampoco (header PO tiene msgid "")
            if ($msgstr !== '' && $currentMsgid !== '') {
                $translations[$currentMsgid] = $msgstr;
            }
            $currentMsgid = null;
        }
    }
    return $translations;
}

/** Elimina las comillas externas de un valor PO y decodifica secuencias de escape. */
function i18n_unquote(string $s): string
{
    $s = trim($s);
    if (str_starts_with($s, '"') && str_ends_with($s, '"')) {
        $s = substr($s, 1, -1);
    }
    return stripcslashes($s);
}
