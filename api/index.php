<?php

declare(strict_types=1);

/**
 * Router principal de la API REST v1.
 *
 * Todas las rutas /api/v1/* llegan aquí gracias a la regla de .htaccess.
 * Autenticación: cabecera Authorization: Bearer {token}
 * Respuestas:    application/json  {"success": true, "data": ...}
 *                                  {"success": false, "error": "..."}
 */

require_once dirname(__DIR__) . '/lib/api_helpers.php';
require_once dirname(__DIR__) . '/lib/api_episode_handler.php';
require_once dirname(__DIR__) . '/lib/api_podcast_handler.php';
require_once dirname(__DIR__) . '/lib/api_pages_handler.php';
require_once dirname(__DIR__) . '/lib/api_social_handler.php';
require_once dirname(__DIR__) . '/lib/api_misc_handlers.php';
require_once dirname(__DIR__) . '/lib/api_system_handler.php';

// Cabeceras de respuesta.
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex');

// CORS: permite peticiones desde cualquier origen (útil para clientes externos).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Responder a peticiones preflight OPTIONS directamente.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Abrir base de datos.
$dbPath = getenv('PODCAST_DB_PATH') ?: dirname(__DIR__) . '/podcast.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    apiError('Error de base de datos.', 500);
}

// Autenticación via Bearer token.
if (!apiAuth($pdo)) {
    apiError('Token de autenticación inválido o ausente.', 401);
}

// Parsear la ruta: eliminar /api/v1 y dividir en segmentos.
$uri    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri    = (string) preg_replace('#^/api/v1#', '', (string) $uri);
$uri    = rtrim($uri, '/');
$parts  = $uri !== '' ? explode('/', ltrim($uri, '/')) : [];

$method   = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$resource = $parts[0] ?? '';
$subpath  = $parts[1] ?? '';        // id numérico o sub-ruta (p.ej. 'clear')
$id       = ($subpath !== '' && ctype_digit($subpath)) ? (int) $subpath : null;

// Cuerpo de la petición (JSON o form-data).
$body = apiParseBody();

// Cargar defaults de podcast para handlers de episodios.
$podcastDefaults = null;

// -------------------------------------------------------------------------
// Enrutamiento
// -------------------------------------------------------------------------

switch ($resource) {

    // --- Episodios ---
    case 'episodes':
        require_once dirname(__DIR__) . '/lib/episode_save_handler.php';
        $podcastDefaults = loadPodcastDefaults($pdo);

        if ($method === 'GET'    && $id === null) { apiListEpisodes($pdo, $_GET); }
        elseif ($method === 'GET'    && $id !== null) { apiGetEpisode($pdo, $id); }
        elseif ($method === 'POST'   && $id === null) { apiCreateEpisode($pdo, $body, $_FILES, $podcastDefaults); }
        elseif ($method === 'POST'   && $id !== null) { apiUpdateEpisode($pdo, $id, $body, $_FILES, $podcastDefaults); }
        elseif ($method === 'DELETE' && $id !== null) { apiDeleteEpisode($pdo, $id); }
        else { apiError('Método no permitido.', 405); }
        break;

    // --- Podcast ---
    case 'podcast':
        if ($method === 'GET')  { apiGetPodcast($pdo); }
        elseif ($method === 'POST') { apiUpdatePodcast($pdo, $body, $_FILES); }
        else { apiError('Método no permitido.', 405); }
        break;

    // --- Páginas ---
    case 'pages':
        if ($method === 'GET'    && $id === null) { apiListPages($pdo, $_GET); }
        elseif ($method === 'GET'    && $id !== null) { apiGetPage($pdo, $id); }
        elseif ($method === 'POST'   && $id === null) { apiCreatePage($pdo, $body); }
        elseif ($method === 'POST'   && $id !== null) { apiUpdatePage($pdo, $id, $body); }
        elseif ($method === 'DELETE' && $id !== null) { apiDeletePage($pdo, $id); }
        else { apiError('Método no permitido.', 405); }
        break;

    // --- Social ---
    case 'social':
        if ($method === 'GET')  { apiGetSocial($pdo); }
        elseif ($method === 'POST') { apiUpdateSocial($pdo, $body); }
        else { apiError('Método no permitido.', 405); }
        break;

    // --- Caché ---
    case 'cache':
        if ($method === 'POST' && $subpath === 'clear')             { apiClearCache(); }
        elseif ($method === 'POST' && $subpath === 'regenerate-images') { apiRegenerateImages($pdo); }
        else { apiError('Ruta de caché no encontrada.', 404); }
        break;

    // --- Estadísticas ---
    case 'stats':
        if ($method === 'GET') { apiGetStats($pdo, $dbPath); }
        else { apiError('Método no permitido.', 405); }
        break;

    // --- Feed ---
    case 'feed':
        if ($method === 'POST' && $subpath === 'regenerate') { apiFeedRegenerate($pdo); }
        else { apiError('Ruta de feed no encontrada.', 404); }
        break;

    // --- Sistema ---
    case 'system':
        if ($method === 'GET'  && $subpath === 'version') { apiGetSystemVersion(); }
        elseif ($method === 'POST' && $subpath === 'update')  { apiSystemUpdate(); }
        else { apiError('Ruta de sistema no encontrada.', 404); }
        break;

    default:
        apiError('Recurso no encontrado.', 404);
}
