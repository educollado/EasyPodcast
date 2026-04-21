<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/download_handler.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

// Obtener el ID del episodio
$episodeId = (int) ($_GET['episode_id'] ?? 0);

if ($episodeId <= 0) {
    http_response_code(400);
    exit;
}

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

    // Obtener la URL del audio
    $audioUrl = getEpisodeAudioUrl($pdo, $episodeId);
    
    if ($audioUrl === null || $audioUrl === '') {
        http_response_code(404);
        exit;
    }

    // Registrar la descarga
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $referer = $_SERVER['HTTP_REFERER'] ?? null;

    registerDownload(
        $pdo,
        $episodeInfo['id'],
        $episodeInfo['guid'],
        $episodeInfo['title'],
        $ipAddress,
        $userAgent,
        $referer
    );

    // Redirigir al audio
    header("Location: " . $audioUrl);
    exit;

} catch (Throwable $e) {
    error_log("Error en download.php: " . $e->getMessage());
    http_response_code(500);
    exit;
}
