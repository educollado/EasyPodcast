<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/upload_service.php';
require_once __DIR__ . '/episode_helpers.php';

/**
 * GET /api/v1/podcast
 * Devuelve los metadatos del canal (fila única de la tabla podcast).
 */
function apiGetPodcast(PDO $pdo): void
{
    $row = activePodcast($pdo);

    if (!$row) {
        apiError('No hay datos de podcast configurados.', 404);
    }

    apiJsonResponse(['success' => true, 'data' => $row]);
}

/**
 * POST /api/v1/podcast
 * Actualiza los metadatos del canal. Solo los campos enviados se modifican.
 * Para subir imágenes, usa multipart/form-data con image_file y hero_image_file.
 */
function apiUpdatePodcast(PDO $pdo, array $body, array $files): void
{
    $existing = activePodcast($pdo);

    if (!$existing) {
        apiError('No hay datos de podcast configurados. Configúralo primero desde el panel admin.', 404);
    }

    // Columnas actualizables vía API.
    $updatable = [
        'title', 'description', 'link', 'language', 'author',
        'owner_name', 'owner_email', 'category', 'explicit',
        'image_url', 'hero_image_url', 'copyright', 'itunes_type',
        'rss_item_limit', 'home_items_per_page',
        'write_audio_metadata', 'cache_enabled', 'app_language', 'public_theme_mode_auto',
    ];

    $sets   = [];
    $params = [];

    // Subida de imagen si se adjunta fichero.
    $imageFileData = is_array($files['image_file'] ?? null) ? $files['image_file'] : ['error' => UPLOAD_ERR_NO_FILE];
    $baseUrl = resolveBaseUrl($pdo);
    $mediaDir = podcastStorageDirectory(dirname(__DIR__), 'images', $existing, multipodcastEnabled($pdo));
    $imageResult = handleImageUpload($imageFileData, $baseUrl, $mediaDir);

    if ($imageResult['error'] !== null) {
        apiError($imageResult['error']);
    }
    if ($imageResult['url'] !== null) {
        $body['image_url'] = $imageResult['url'];
    }

    $heroFileData = is_array($files['hero_image_file'] ?? null) ? $files['hero_image_file'] : ['error' => UPLOAD_ERR_NO_FILE];
    $heroResult = handleHeroImageUpload($heroFileData, $baseUrl, $mediaDir);

    if ($heroResult['error'] !== null) {
        apiError($heroResult['error']);
    }
    if ($heroResult['url'] !== null) {
        $body['hero_image_url'] = $heroResult['url'];
    }

    foreach ($updatable as $col) {
        if (array_key_exists($col, $body)) {
            $sets[]           = "$col = :$col";
            $params[":$col"]  = $body[$col];
        }
    }

    if (empty($sets)) {
        apiError('No se proporcionaron campos para actualizar.');
    }

    $params[':id'] = (int) $existing['id'];
    $pdo->prepare("UPDATE podcast SET " . implode(', ', $sets) . " WHERE id = :id")
        ->execute($params);

    clearWebCache();

    $updated = podcastById($pdo, (int) $existing['id']);
    apiJsonResponse(['success' => true, 'data' => $updated ?: []]);
}
