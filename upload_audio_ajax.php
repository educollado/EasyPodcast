<?php

declare(strict_types=1);

// Endpoint AJAX para pre-subir el audio grabado desde el micrófono antes
// de que el usuario envíe el formulario principal. Así el audio no se pierde
// si hay un error de validación y la página recarga.
//
// Devuelve JSON: { url, mime, size } en éxito, o { error } en fallo.

require_once __DIR__ . '/lib/session.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

if (!isset($_SESSION['admin_user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

if (!csrf_is_valid()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

require_once __DIR__ . '/lib/episode_save_handler.php'; // incluye feed_builder.php (resolveBaseUrl)

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $podcastDefaults = loadPodcastDefaults($pdo);
    $baseUrl         = resolveBaseUrl($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos']);
    exit;
}

$audiosDir     = __DIR__ . '/audios';
$audioFileData = is_array($_FILES['audio_file'] ?? null)
    ? $_FILES['audio_file']
    : ['error' => UPLOAD_ERR_NO_FILE];

$formForUpload = episodeFormDefaults($podcastDefaults);
$result        = handleAudioUpload($audioFileData, $formForUpload, $podcastDefaults, $baseUrl, $audiosDir);

if ($result['error'] !== null) {
    http_response_code(400);
    echo json_encode(['error' => $result['error']]);
    exit;
}

if (!$result['uploaded']) {
    http_response_code(400);
    echo json_encode(['error' => 'No se recibió ningún fichero de audio.']);
    exit;
}

echo json_encode([
    'url'  => $result['url'],
    'mime' => $result['mime'],
    'size' => $result['size'],
]);
