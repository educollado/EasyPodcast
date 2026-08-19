<?php

declare(strict_types=1);

/**
 * Gestión del tema visual del sitio.
 * El tema se almacena en podcast.admin_theme y se aplica server-side
 * mediante el atributo data-theme en <html>, tanto en admin como en páginas públicas.
 */

const ADMIN_THEMES = [
    'easypodcast'  => 'EasyPodcast',
    'corporate'    => 'Corporate',
    'default'      => 'Amber Parchment',
    'oscuro'       => 'Ember Noir',
    'agua'         => 'Arctic Tide',
    'fuego'        => 'Crimson Dusk',
    'invierno'     => 'Frost Haven',
    'hacker'       => 'Matrix Core',
    'monokai'      => 'Monokai',
    'pink-essence' => 'Pink Essence',
    'monocromo'    => 'Silver Void',
];

const PUBLIC_THEME_MODES = [
    'normal' => 'Normal',
    'auto'   => 'Automático',
];

/**
 * Lee el tema activo desde BD y lo guarda en $GLOBALS['_admin_theme'].
 * Silencioso ante errores: usa 'easypodcast' como fallback.
 */
function loadAdminTheme(string $dbPath): void
{
    $GLOBALS['_admin_theme'] = 'easypodcast';
    $GLOBALS['_public_theme_mode'] = 'normal';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $columns = array_column(
            $pdo->query('PRAGMA table_info(podcast)')->fetchAll(),
            'name'
        );

        if (in_array('admin_theme', $columns, true)) {
            $theme = $pdo->query('SELECT admin_theme FROM podcast LIMIT 1')->fetchColumn();
            if (is_string($theme) && $theme !== '' && isset(ADMIN_THEMES[$theme])) {
                $GLOBALS['_admin_theme'] = $theme;
            }
        }

        if (in_array('public_theme_mode_auto', $columns, true)) {
            $modeAuto = $pdo->query('SELECT public_theme_mode_auto FROM podcast LIMIT 1')->fetchColumn();
            if ((int) $modeAuto === 1) {
                $GLOBALS['_public_theme_mode'] = 'auto';
            }
        }
    } catch (Throwable $e) {
        // Silencioso: fallback a tema y modo por defecto.
    }
}

/**
 * Devuelve el slug del tema activo ('easypodcast' si no se ha cargado aún).
 */
function adminTheme(): string
{
    return isset($GLOBALS['_admin_theme']) ? (string) $GLOBALS['_admin_theme'] : 'easypodcast';
}

/**
 * Devuelve el modo de tema público activo ('normal' o 'auto').
 */
function publicThemeMode(): string
{
    $mode = isset($GLOBALS['_public_theme_mode']) ? (string) $GLOBALS['_public_theme_mode'] : 'normal';
    return isset(PUBLIC_THEME_MODES[$mode]) ? $mode : 'normal';
}
