<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/cache_service.php';

/** Campos de la tabla social (mismo orden que en social_handler.php). */
const API_SOCIAL_FIELDS = ['blog', 'linkedin', 'mastodon', 'x', 'pixelfed', 'instagram', 'youtube', 'github', 'bluesky'];

/**
 * GET /api/v1/social
 */
function apiGetSocial(PDO $pdo): void
{
    $tableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='social' LIMIT 1")
        ->fetchColumn();

    if (!$tableExists) {
        apiJsonResponse(['success' => true, 'data' => []]);
    }

    $stmt = $pdo->prepare('SELECT * FROM social WHERE podcast_id = :podcast_id LIMIT 1');
    $stmt->execute([':podcast_id' => activePodcastId($pdo)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    apiJsonResponse(['success' => true, 'data' => $row ?: []]);
}

/**
 * POST /api/v1/social
 * Actualiza (o inserta) los enlaces de redes sociales.
 * Solo se admiten los campos de API_SOCIAL_FIELDS; los valores deben ser URLs válidas o cadena vacía.
 */
function apiUpdateSocial(PDO $pdo, array $body): void
{
    $tableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='social' LIMIT 1")
        ->fetchColumn();

    if (!$tableExists) {
        apiError('La tabla social no existe. Ejecuta las migraciones.', 500);
    }

    $podcastId = activePodcastId($pdo);
    $existingStmt = $pdo->prepare('SELECT * FROM social WHERE podcast_id = :podcast_id LIMIT 1');
    $existingStmt->execute([':podcast_id' => $podcastId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    // Construir mapa de valores: partir de los existentes y sobrescribir con los enviados.
    $values = [];
    foreach (API_SOCIAL_FIELDS as $field) {
        $val = array_key_exists($field, $body) ? trim((string) $body[$field]) : (string) ($existing[$field] ?? '');
        if ($val !== '' && filter_var($val, FILTER_VALIDATE_URL) === false) {
            apiError("El valor de \"$field\" no es una URL válida.");
        }
        $values[$field] = $val;
    }

    $params = [];
    foreach (API_SOCIAL_FIELDS as $f) {
        $params[':' . $f] = $values[$f];
    }

    if ($existing) {
        $sets = implode(', ', array_map(fn($f) => "$f = :$f", API_SOCIAL_FIELDS));
        $params[':id'] = (int) $existing['id'];
        $params[':podcast_id'] = $podcastId;
        $pdo->prepare("UPDATE social SET $sets WHERE id = :id AND podcast_id = :podcast_id")->execute($params);
    } else {
        $cols         = implode(', ', API_SOCIAL_FIELDS);
        $placeholders = implode(', ', array_map(fn($f) => ":$f", API_SOCIAL_FIELDS));
        $params[':podcast_id'] = $podcastId;
        $pdo->prepare("INSERT INTO social (podcast_id, $cols) VALUES (:podcast_id, $placeholders)")->execute($params);
    }

    clearWebCache();

    $updatedStmt = $pdo->prepare('SELECT * FROM social WHERE podcast_id = :podcast_id LIMIT 1');
    $updatedStmt->execute([':podcast_id' => $podcastId]);
    $updated = $updatedStmt->fetch(PDO::FETCH_ASSOC);
    apiJsonResponse(['success' => true, 'data' => $updated ?: []]);
}
