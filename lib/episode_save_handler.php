<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';
require_once __DIR__ . '/upload_service.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/sitemap_builder.php';

/**
 * Valida el formulario de episodio. Función pura: sin acceso a BD ni efectos laterales.
 *
 * @param array $form
 * @return ?string null si válido, string de error si no
 */
function validateEpisodeForm(array $form): ?string
{
    if (!in_array($form['explicit'] ?? '', ['', '0', '1'], true)) {
        return 'El valor de explícito no es válido.';
    }

    if (!in_array($form['status'] ?? '', ['draft', 'published'], true)) {
        return 'El estado debe ser draft o published.';
    }

    if (($form['episode_type'] ?? '') !== '' && !in_array($form['episode_type'], ['full', 'trailer', 'bonus'], true)) {
        return 'El tipo de episodio debe ser full, trailer o bonus.';
    }

    if (($form['title'] ?? '') === '' || ($form['description'] ?? '') === '' || ($form['pub_date'] ?? '') === '') {
        return 'Título, descripción y fecha de publicación son obligatorios.';
    }

    if (normalizeDateTime($form['pub_date']) === null) {
        return 'La fecha de publicación no es válida.';
    }

    foreach (['audio_size_bytes', 'season_number', 'episode_number'] as $field) {
        $value = $form[$field] ?? '';
        if ($value === '') {
            continue;
        }
        if (!ctype_digit($value) || (int) $value < 0) {
            return 'Revisa los campos numéricos: deben ser enteros positivos.';
        }
    }

    return null;
}

/**
 * Devuelve el array inicial del formulario con defaults de podcast aplicados.
 *
 * @param array $podcastDefaults
 * @return array
 */
function episodeFormDefaults(array $podcastDefaults): array
{
    return [
        'guid'             => '',
        'title'            => '',
        'description'      => '',
        'link'             => '',
        'pub_date'         => date('Y-m-d\\TH:i'),
        'audio_url'        => '',
        'audio_mime_type'  => 'audio/mpeg',
        'audio_size_bytes' => '',
        'duration'         => '',
        'explicit'         => '',
        'season_number'    => '',
        'episode_number'   => '',
        'episode_type'     => '',
        'image_url'        => $podcastDefaults['image_url'] ?? '',
        'author'           => $podcastDefaults['author'] ?? '',
        'status'           => 'draft',
    ];
}

/**
 * Carga los valores por defecto del podcast desde la BD, incluyendo migración si es necesario.
 *
 * @param PDO $pdo
 * @return array{title: string, image_url: string, author: string, write_audio_metadata: int}
 */
function loadPodcastDefaults(PDO $pdo): array
{
    $defaults = ['title' => '', 'image_url' => '', 'author' => '', 'write_audio_metadata' => 0];

    $podcastTableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'podcast' LIMIT 1")
        ->fetchColumn();

    if (!$podcastTableExists) {
        return $defaults;
    }

    $podcastColumns = $pdo->query('PRAGMA table_info(podcast)')->fetchAll();
    $hasWriteAudioMetadata = false;
    foreach ($podcastColumns as $col) {
        if (($col['name'] ?? '') === 'write_audio_metadata') {
            $hasWriteAudioMetadata = true;
            break;
        }
    }
    if (!$hasWriteAudioMetadata) {
        $pdo->exec('ALTER TABLE podcast ADD COLUMN write_audio_metadata INTEGER NOT NULL DEFAULT 0');
    }

    $podcastData = $pdo->query('SELECT title, image_url, owner_name, write_audio_metadata FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
    if ($podcastData) {
        $defaults['title']                = trim((string) ($podcastData['title'] ?? ''));
        $defaults['image_url']            = trim((string) ($podcastData['image_url'] ?? ''));
        $defaults['author']               = trim((string) ($podcastData['owner_name'] ?? ''));
        $defaults['write_audio_metadata'] = (int) ($podcastData['write_audio_metadata'] ?? 0);
    }

    return $defaults;
}

/**
 * Orquesta la creación/actualización completa de un episodio.
 *
 * @param PDO    $pdo
 * @param array  $form                 Datos del formulario (ya trimados)
 * @param bool   $isEditing
 * @param ?int   $episodeId
 * @param array  $podcastDefaults
 * @param array  $files                $_FILES
 * @param bool   $rewriteAudioMetadata Reescritura manual de ID3 en edición
 * @return array{error: string, notice: string, id3Notice: string, form: array}
 */
function saveEpisode(
    PDO $pdo,
    array $form,
    bool $isEditing,
    ?int $episodeId,
    array $podcastDefaults,
    array $files,
    bool $rewriteAudioMetadata
): array {
    // 1. Validación básica del formulario.
    $validationError = validateEpisodeForm($form);
    if ($validationError !== null) {
        return ['error' => $validationError, 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }

    $pubDateNormalized = normalizeDateTime($form['pub_date']);
    $baseUrl = resolveBaseUrl($pdo);
    $imagesDir = dirname(__DIR__) . '/images';
    $audiosDir = dirname(__DIR__) . '/audios';

    // 2. Subida imagen.
    $imageFileData = is_array($files['image_file'] ?? null) ? $files['image_file'] : ['error' => UPLOAD_ERR_NO_FILE];
    $imageResult = handleImageUpload($imageFileData, $baseUrl, $imagesDir);
    if ($imageResult['error'] !== null) {
        return ['error' => $imageResult['error'], 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }
    if ($imageResult['url'] !== null) {
        $form['image_url'] = $imageResult['url'];
    }

    // 3. Subida audio.
    $audioFileData = is_array($files['audio_file'] ?? null) ? $files['audio_file'] : ['error' => UPLOAD_ERR_NO_FILE];
    $audioResult = handleAudioUpload($audioFileData, $form, $podcastDefaults, $baseUrl, $audiosDir);
    if ($audioResult['error'] !== null) {
        return ['error' => $audioResult['error'], 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }
    $id3Notice = '';
    if ($audioResult['uploaded']) {
        $form['audio_url']        = (string) $audioResult['url'];
        $form['audio_mime_type']  = (string) $audioResult['mime'];
        $form['audio_size_bytes'] = (string) $audioResult['size'];
        $id3Notice = $audioResult['id3Notice'];
    }

    // 4. Validación de audio post-subida.
    if ($form['audio_url'] === '') {
        return ['error' => 'Debes indicar la URL de audio o subir un fichero de audio.', 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }
    if ($form['audio_mime_type'] === '') {
        return ['error' => 'El MIME del audio es obligatorio.', 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }
    if ($form['audio_size_bytes'] === '' || !ctype_digit($form['audio_size_bytes']) || (int) $form['audio_size_bytes'] <= 0) {
        return ['error' => 'El tamaño del audio debe ser un entero mayor que 0.', 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }

    // 5. Fallbacks de defaults del podcast.
    if ($form['image_url'] === '' && ($podcastDefaults['image_url'] ?? '') !== '') {
        $form['image_url'] = $podcastDefaults['image_url'];
    }
    if ($form['author'] === '' && ($podcastDefaults['author'] ?? '') !== '') {
        $form['author'] = $podcastDefaults['author'];
    }

    // 6. Reescritura ID3 en edición (sin subida nueva de audio).
    $shouldRewriteMetadata = $isEditing
        && !$audioResult['uploaded']
        && ($rewriteAudioMetadata || ($podcastDefaults['write_audio_metadata'] ?? 0) === 1);

    if ($shouldRewriteMetadata) {
        $id3Result = handleId3Rewrite($form['audio_url'], $form, $podcastDefaults);
        $id3Notice = $id3Result['id3Notice'];
        if ($id3Result['sizeBytes'] !== null) {
            $form['audio_size_bytes'] = (string) $id3Result['sizeBytes'];
        }
    }

    // 7. Link público autogenerado solo al crear.
    if (!$isEditing && $form['link'] === '') {
        $form['link'] = buildEpisodePublicLink($baseUrl, $pubDateNormalized, $form['title']);
    }

    // 8. GUID autogenerado si está vacío.
    if ($form['guid'] === '') {
        $form['guid'] = generateGuid();
    }

    // 9. Persistencia en BD.
    if ($isEditing && $episodeId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE episodes
             SET guid = :guid,
                 title = :title,
                 description = :description,
                 link = :link,
                 pub_date = :pub_date,
                 audio_url = :audio_url,
                 audio_mime_type = :audio_mime_type,
                 audio_size_bytes = :audio_size_bytes,
                 duration = :duration,
                 explicit = :explicit,
                 season_number = :season_number,
                 episode_number = :episode_number,
                 episode_type = :episode_type,
                 image_url = :image_url,
                 author = :author,
                 status = :status,
                 updated_at = datetime(\'now\')
             WHERE id = :id'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO episodes
             (guid, title, description, link, pub_date, audio_url, audio_mime_type, audio_size_bytes,
              duration, explicit, season_number, episode_number, episode_type, image_url, author, status, updated_at)
             VALUES
             (:guid, :title, :description, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes,
              :duration, :explicit, :season_number, :episode_number, :episode_type, :image_url, :author, :status, datetime(\'now\'))'
        );
    }

    $params = [
        ':guid'             => $form['guid'],
        ':title'            => $form['title'],
        ':description'      => $form['description'],
        ':link'             => $form['link'] !== '' ? $form['link'] : null,
        ':pub_date'         => $pubDateNormalized,
        ':audio_url'        => $form['audio_url'],
        ':audio_mime_type'  => $form['audio_mime_type'],
        ':audio_size_bytes' => (int) $form['audio_size_bytes'],
        ':duration'         => $form['duration'] !== '' ? $form['duration'] : null,
        ':explicit'         => $form['explicit'] !== '' ? (int) $form['explicit'] : null,
        ':season_number'    => $form['season_number'] !== '' ? (int) $form['season_number'] : null,
        ':episode_number'   => $form['episode_number'] !== '' ? (int) $form['episode_number'] : null,
        ':episode_type'     => $form['episode_type'] !== '' ? $form['episode_type'] : null,
        ':image_url'        => $form['image_url'] !== '' ? $form['image_url'] : null,
        ':author'           => $form['author'] !== '' ? $form['author'] : null,
        ':status'           => $form['status'],
    ];
    if ($isEditing && $episodeId !== null) {
        $params[':id'] = $episodeId;
    }
    $stmt->execute($params);

    // 10. Mensaje y reset del formulario al crear.
    $notice = ($isEditing && $episodeId !== null)
        ? 'Capítulo actualizado correctamente.'
        : 'Capítulo guardado correctamente.';

    if (!$isEditing || $episodeId === null) {
        $form = episodeFormDefaults($podcastDefaults);
    }

    // 11. Regenerar feed.xml, sitemap.xml y limpiar caché.
    try {
        writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
        writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
    } catch (Throwable $feedError) {
        $notice .= ' (Aviso: no se pudo regenerar feed.xml/sitemap.xml)';
    }
    if (!clearWebCache()) {
        $notice .= ' (Aviso: no se pudo limpiar completamente la caché)';
    }
    if ($id3Notice !== '') {
        $notice .= ' (' . $id3Notice . ')';
    }

    return ['error' => '', 'notice' => $notice, 'id3Notice' => $id3Notice, 'form' => $form];
}
