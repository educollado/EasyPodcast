<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/episode_save_handler.php';

/**
 * Prepara un array de episodio para la respuesta API.
 * Expone 'short_description' (BD) como 'description' (API) para alinear la nomenclatura.
 */
function episodeToApiResponse(array $episode): array
{
    if (array_key_exists('short_description', $episode)) {
        $episode['description'] = $episode['short_description'];
        unset($episode['short_description']);
    }
    return $episode;
}

/**
 * GET /api/v1/episodes
 * Lista paginada con filtro opcional de status.
 * Parámetros: page (int), limit (int, max 100), status (draft|scheduled|published).
 */
function apiListEpisodes(PDO $pdo, array $params): void
{
    $page   = max(1, (int) ($params['page']   ?? 1));
    $limit  = min(100, max(1, (int) ($params['limit'] ?? 20)));
    $status = (string) ($params['status'] ?? '');
    $offset = ($page - 1) * $limit;

    $where  = 'WHERE podcast_id = :podcast_id';
    $binds  = [':podcast_id' => activePodcastId($pdo)];

    if ($status !== '' && in_array($status, ['draft', 'scheduled', 'published'], true)) {
        $where         .= ' AND status = :status';
        $binds[':status'] = $status;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM episodes $where");
    $countStmt->execute($binds ?: []);
    $total = (int) $countStmt->fetchColumn();

    $fetchBinds            = $binds;
    $fetchBinds[':limit']  = $limit;
    $fetchBinds[':offset'] = $offset;

    $stmt = $pdo->prepare(
        "SELECT * FROM episodes $where
         ORDER BY pub_date DESC, created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->execute($fetchBinds);
    $episodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    apiJsonResponse([
        'success' => true,
        'data'    => [
            'items'       => array_map('episodeToApiResponse', $episodes),
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ],
    ]);
}

/**
 * GET /api/v1/episodes/{id}
 */
function apiGetEpisode(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id AND podcast_id = :podcast_id LIMIT 1');
    $stmt->execute([':id' => $id, ':podcast_id' => activePodcastId($pdo)]);
    $episode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$episode) {
        apiError('Episodio no encontrado.', 404);
    }

    apiJsonResponse(['success' => true, 'data' => episodeToApiResponse($episode)]);
}

/**
 * POST /api/v1/episodes
 * Campos requeridos: title, description, audio_url (o audio_file), audio_size_bytes, audio_mime_type, status.
 */
function apiCreateEpisode(PDO $pdo, array $body, array $files, array $podcastDefaults): void
{
    // Mapear 'description' (nombre API) a 'short_description' (nombre interno de BD).
    if (isset($body['description']) && !isset($body['short_description'])) {
        $body['short_description'] = $body['description'];
    }
    unset($body['description']);
    // Normalizar valores a string para compatibilidad con validateEpisodeForm.
    $bodyStrings = array_map(fn($v) => $v !== null ? (string) $v : '', $body);
    $form        = array_merge(episodeFormDefaults($podcastDefaults), $bodyStrings);

    $result = saveEpisode($pdo, $form, false, null, $podcastDefaults, $files, false);

    if ($result['error'] !== '') {
        apiError($result['error']);
    }

    // lastInsertId() es seguro aquí: saveEpisode solo hace SELECT/feed-write después del INSERT.
    $lastId = (int) $pdo->lastInsertId();
    $stmt   = $pdo->prepare('SELECT * FROM episodes WHERE id = :id AND podcast_id = :podcast_id LIMIT 1');
    $stmt->execute([':id' => $lastId, ':podcast_id' => activePodcastId($pdo)]);
    $episode = $stmt->fetch(PDO::FETCH_ASSOC);

    apiJsonResponse(['success' => true, 'data' => episodeToApiResponse($episode ?: [])], 201);
}

/**
 * POST /api/v1/episodes/{id}
 * Solo los campos enviados sobreescriben los existentes.
 */
function apiUpdateEpisode(PDO $pdo, int $id, array $body, array $files, array $podcastDefaults): void
{
    $stmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id AND podcast_id = :podcast_id LIMIT 1');
    $stmt->execute([':id' => $id, ':podcast_id' => activePodcastId($pdo)]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        apiError('Episodio no encontrado.', 404);
    }

    // Mapear 'description' (nombre API) a 'short_description' (nombre interno de BD).
    if (isset($body['description']) && !isset($body['short_description'])) {
        $body['short_description'] = $body['description'];
    }
    unset($body['description']);
    // Normalizar valores existentes de BD a string (ctype_digit espera strings).
    $existingStrings = array_map(fn($v) => $v !== null ? (string) $v : '', $existing);
    $bodyStrings     = array_map(fn($v) => $v !== null ? (string) $v : '', $body);

    $form = array_merge(episodeFormDefaults($podcastDefaults), $existingStrings, $bodyStrings);

    $result = saveEpisode($pdo, $form, true, $id, $podcastDefaults, $files, false);

    if ($result['error'] !== '') {
        apiError($result['error']);
    }

    $stmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id AND podcast_id = :podcast_id LIMIT 1');
    $stmt->execute([':id' => $id, ':podcast_id' => activePodcastId($pdo)]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);

    apiJsonResponse(['success' => true, 'data' => episodeToApiResponse($updated ?: [])]);
}

/**
 * DELETE /api/v1/episodes/{id}
 */
function apiDeleteEpisode(PDO $pdo, int $id): void
{
    // Guardar URLs de archivos antes de borrar el registro.
    $stmt = $pdo->prepare('SELECT audio_url, image_url FROM episodes WHERE id = :id AND podcast_id = :podcast_id LIMIT 1');
    $stmt->execute([':id' => $id, ':podcast_id' => activePodcastId($pdo)]);
    $episodeFiles = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$episodeFiles) {
        apiError('Episodio no encontrado.', 404);
    }

    $pdo->prepare('DELETE FROM episodes WHERE id = :id AND podcast_id = :podcast_id')->execute([':id' => $id, ':podcast_id' => activePodcastId($pdo)]);

    // Eliminar archivos huérfanos si ningún otro episodio los usa.
    $audioUrl = (string) ($episodeFiles['audio_url'] ?? '');
    if ($audioUrl !== '') {
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE audio_url = :url');
        $cntStmt->execute([':url' => $audioUrl]);
        if ((int) $cntStmt->fetchColumn() === 0) {
            $localAudio = resolveLocalAudioPathFromUrl($audioUrl);
            if ($localAudio !== null) {
                @unlink($localAudio);
            }
        }
    }
    $imageUrl = (string) ($episodeFiles['image_url'] ?? '');
    if ($imageUrl !== '' && !isImageUrlInUse($pdo, $imageUrl)) {
        $localImage = resolveLocalImagePathFromUrl($imageUrl);
        if ($localImage !== null) {
            @unlink($localImage);
        }
    }

    // Regenerar feed/sitemap tras borrado; los fallos no bloquean la respuesta.
    try {
        require_once __DIR__ . '/../feed_builder.php';
        require_once __DIR__ . '/sitemap_builder.php';
        writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
        writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
    } catch (Throwable $e) {
        // Ignorar: el episodio ya fue borrado.
    }
    clearWebCache();

    apiJsonResponse(['success' => true, 'data' => ['deleted' => true]]);
}
