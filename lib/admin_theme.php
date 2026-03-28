<?php

declare(strict_types=1);

/**
 * Gestión del tema visual del sitio.
 * El tema se almacena en podcast.admin_theme y se aplica server-side
 * mediante el atributo data-theme en <html>, tanto en admin como en páginas públicas.
 */

const ADMIN_THEMES = [
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

/**
 * Lee el tema activo desde BD y lo guarda en $GLOBALS['_admin_theme'].
 * Silencioso ante errores: usa 'default' como fallback.
 */
function loadAdminTheme(string $dbPath): void
{
    try {
        $pdo   = new PDO('sqlite:' . $dbPath);
        $theme = $pdo->query('SELECT admin_theme FROM podcast LIMIT 1')->fetchColumn();
        if (is_string($theme) && $theme !== '' && isset(ADMIN_THEMES[$theme])) {
            $GLOBALS['_admin_theme'] = $theme;
            return;
        }
    } catch (Throwable $e) {
        // Silencioso: fallback al tema por defecto.
    }
    $GLOBALS['_admin_theme'] = 'default';
}

/**
 * Devuelve el slug del tema activo ('default' si no se ha cargado aún).
 */
function adminTheme(): string
{
    return isset($GLOBALS['_admin_theme']) ? (string) $GLOBALS['_admin_theme'] : 'default';
}
