<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

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

const PUBLIC_THEME_MODE_COOKIE = 'easypodcast_theme_mode';

const PUBLIC_THEME_MODES = [
    'normal' => 'Normal',
    'auto'   => 'Automático',
];

/**
 * Normaliza el modo de tema público.
 */
function normalizePublicThemeMode(?string $mode): string
{
    $mode = is_string($mode) ? trim(strtolower($mode)) : '';
    return isset(PUBLIC_THEME_MODES[$mode]) ? $mode : 'normal';
}

/**
 * Construye una URL sobre la request actual cambiando solo theme_mode.
 */
function buildPublicThemeModeUrl(string $mode, ?string $requestUri = null): string
{
    $requestUri = $requestUri !== null && $requestUri !== ''
        ? $requestUri
        : (string) ($_SERVER['REQUEST_URI'] ?? '/');

    $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
    $path = $path !== '' ? $path : '/';

    $queryString = (string) (parse_url($requestUri, PHP_URL_QUERY) ?? '');
    $params = [];
    if ($queryString !== '') {
        parse_str($queryString, $params);
    }

    $params['theme_mode'] = normalizePublicThemeMode($mode);

    $query = http_build_query($params);
    return $path . ($query !== '' ? '?' . $query : '');
}

/**
 * Si llega theme_mode por query string, lo persiste en cookie y redirige
 * a la misma URL sin ese parámetro para no ensuciar la navegación.
 */
function handlePublicThemeModePreference(): void
{
    if (PHP_SAPI === 'cli' || headers_sent() || !isset($_GET['theme_mode'])) {
        return;
    }

    $rawMode = (string) $_GET['theme_mode'];
    $mode = normalizePublicThemeMode($rawMode);
    if (!isset(PUBLIC_THEME_MODES[$rawMode])) {
        return;
    }

    setcookie(PUBLIC_THEME_MODE_COOKIE, $mode, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => isSecureHttpRequest(),
        'samesite' => 'Lax',
    ]);

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/');
    $path = $path !== '' ? $path : '/';

    $queryString = (string) (parse_url($requestUri, PHP_URL_QUERY) ?? '');
    $params = [];
    if ($queryString !== '') {
        parse_str($queryString, $params);
    }
    unset($params['theme_mode']);

    $location = $path;
    if ($params !== []) {
        $location .= '?' . http_build_query($params);
    }

    header('Location: ' . $location, true, 302);
    exit;
}

/**
 * Script mínimo para aplicar data-theme-mode antes de cargar estilos.
 */
function publicThemeModeBootstrapScript(): string
{
    return '<script src="/assets/js/theme-mode.js"></script>';
}

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
