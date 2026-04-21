<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/download_handler.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

// Obtener el ID del episodio
$episodeId = (int) ($_GET['episode_id'] ?? 0);
$action = (string) ($_GET['action'] ?? 'download');

if ($episodeId <= 0) {
    http_response_code(400);
    exit;
}

// Determinar si es AJAX (para play) o no (para download con redirect)
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Obtener información del episodio
    $episodeInfo = getEpisodeInfo($pdo, $episodeId);
    
    if ($episodeInfo === null) {
        http_response_code(404);
        exit;
    }

    // Registrar la accion
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $referer = $_SERVER['HTTP_REFERER'] ?? null;

    // Validar action
    if (!in_array($action, ['download', 'play'], true)) {
        $action = 'download';
    }

    registerDownload(
        $pdo,
        $episodeInfo['id'],
        $episodeInfo['guid'],
        $episodeInfo['title'],
        $ipAddress,
        $action,
        $userAgent,
        $referer
    );

    // Si es descarga y no es AJAX, redirigir al audio
    if ($action === 'download' && !$isAjax) {
        $audioUrl = getEpisodeAudioUrl($pdo, $episodeId);
        if ($audioUrl !== null && $audioUrl !== '') {
            header("Location: " . $audioUrl);
            exit;
        }
        http_response_code(404);
        exit;
    }

    // Para AJAX (play) o si solo queremos registrar sin redirect
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'action' => $action, 'episode_id' => $episodeId]);
    exit;

} catch (Throwable $e) {
    error_log("Error en track.php: " . $e->getMessage());
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        http_response_code(500);
    }
    exit;
}
