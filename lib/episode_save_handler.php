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
 * Al no tener dependencias externas es directamente testeable sin base de datos ni ficheros.
 *
 * @param array $form
 * @return ?string null si válido, string de error si no
 */
function validateEpisodeForm(array $form): ?string
{
    // explicit admite tres estados: heredar del podcast (''), no ('0') o sí ('1').
    if (!in_array($form['explicit'] ?? '', ['', '0', '1'], true)) {
        return 'El valor de explícito no es válido.';
    }

    if (!in_array($form['status'] ?? '', ['draft', 'published'], true)) {
        return 'El estado debe ser draft o published.';
    }

    // episode_type es opcional; si se informa debe ser uno de los valores del estándar iTunes.
    if (($form['episode_type'] ?? '') !== '' && !in_array($form['episode_type'], ['full', 'trailer', 'bonus'], true)) {
        return 'El tipo de episodio debe ser full, trailer o bonus.';
    }

    if (($form['title'] ?? '') === '' || ($form['description'] ?? '') === '') {
        return 'Título y descripción son obligatorios.';
    }

    // Valida el formato solo si pub_date viene informado (puede llegar vacío cuando
    // saveEpisode aún no lo ha auto-completado, o en tests que pasen un valor explícito).
    if (($form['pub_date'] ?? '') !== '' && normalizeDateTime($form['pub_date']) === null) {
        return 'La fecha de publicación no es válida.';
    }

    // Los tres campos numéricos son opcionales (cadena vacía permitida).
    // Si se informan deben ser enteros no negativos representados como string.
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
 * Se usa al mostrar el formulario vacío (crear) y al limpiarlo tras un guardado exitoso.
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
        // pub_date se gestiona automáticamente: no se muestra en el formulario.
        // Se asigna en saveEpisode según el estado del episodio.
        'pub_date'         => '',
        'audio_url'        => '',
        'audio_mime_type'  => 'audio/mpeg',
        'audio_size_bytes' => '',
        'duration'         => '',
        'explicit'         => '',
        'season_number'    => '',
        'episode_number'   => '',
        'episode_type'     => '',
        // imagen y autor se prefijan con los valores del podcast para no tener que
        // rellenarlos manualmente en cada episodio cuando son siempre los mismos.
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

    // La tabla podcast puede no existir en instalaciones nuevas; devolvemos defaults vacíos.
    $podcastTableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'podcast' LIMIT 1")
        ->fetchColumn();

    if (!$podcastTableExists) {
        return $defaults;
    }

    $podcastData = $pdo->query('SELECT title, image_url, owner_name, write_audio_metadata FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
    if ($podcastData) {
        $defaults['title']                = trim((string) ($podcastData['title'] ?? ''));
        $defaults['image_url']            = trim((string) ($podcastData['image_url'] ?? ''));
        // El campo del podcast se llama owner_name; lo exponemos como 'author' para el episodio.
        $defaults['author']               = trim((string) ($podcastData['owner_name'] ?? ''));
        $defaults['write_audio_metadata'] = (int) ($podcastData['write_audio_metadata'] ?? 0);
    }

    return $defaults;
}

/**
 * Orquesta la creación/actualización completa de un episodio.
 *
 * Secuencia: validación → subida imagen → subida audio → reescritura ID3
 *            → fallbacks defaults → persistencia BD → feed/sitemap/caché.
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
    // 0. Estado previo del episodio (solo en edición).
    // Necesitamos saber si el episodio estaba en draft para detectar la primera publicación
    // y asignar la fecha real de publicación en ese momento.
    $previousStatus  = null;
    $existingPubDate = null;
    if ($isEditing && $episodeId !== null) {
        $prevStmt = $pdo->prepare('SELECT status, pub_date FROM episodes WHERE id = :id LIMIT 1');
        $prevStmt->execute([':id' => $episodeId]);
        $prev = $prevStmt->fetch();
        if ($prev) {
            $previousStatus  = (string) ($prev['status'] ?? '');
            $existingPubDate = (string) ($prev['pub_date'] ?? '');
        }
    }

    // ¿Es la primera vez que este episodio se publica?
    $isFirstPublish = $form['status'] === 'published'
        && $isEditing
        && $previousStatus === 'draft';

    // Auto-asignación de pub_date (el formulario ya no expone este campo):
    // - Episodio nuevo (draft o published): fecha actual (momento de creación).
    // - Re-edición de draft sin cambio de estado: conserva la fecha de creación almacenada.
    // - Primera publicación (draft → published): actualiza a la fecha actual (momento de publicación).
    // - Re-edición de published: conserva la fecha de publicación original.
    if (!$isEditing) {
        $form['pub_date'] = date('Y-m-d\\TH:i');
    } elseif ($isFirstPublish) {
        $form['pub_date'] = date('Y-m-d\\TH:i');
    } else {
        $form['pub_date'] = $existingPubDate !== '' ? $existingPubDate : date('Y-m-d\\TH:i');
    }

    // 1. Validación básica del formulario.
    // Se hace antes de cualquier operación de I/O para fallar rápido.
    $validationError = validateEpisodeForm($form);
    if ($validationError !== null) {
        return ['error' => $validationError, 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }

    // Normalizamos la fecha aquí una vez; la usaremos en el link autogenerado y en la BD.
    $pubDateNormalized = normalizeDateTime($form['pub_date']);
    $baseUrl = resolveBaseUrl($pdo);

    // dirname(__DIR__) sube un nivel desde lib/ hasta la raíz del proyecto.
    $imagesDir = dirname(__DIR__) . '/images';
    $audiosDir = dirname(__DIR__) . '/audios';

    // 2. Subida imagen.
    // Si no viene el índice 'image_file' en $files, simulamos un array con UPLOAD_ERR_NO_FILE
    // para que handleImageUpload lo trate como "sin subida" en lugar de lanzar un aviso.
    $imageFileData = is_array($files['image_file'] ?? null) ? $files['image_file'] : ['error' => UPLOAD_ERR_NO_FILE];
    $imageResult = handleImageUpload($imageFileData, $baseUrl, $imagesDir);
    if ($imageResult['error'] !== null) {
        return ['error' => $imageResult['error'], 'notice' => '', 'id3Notice' => '', 'form' => $form];
    }
    if ($imageResult['url'] !== null) {
        // Solo sobreescribimos image_url si se subió imagen nueva; si no, conservamos
        // el valor que el usuario escribió en el campo de texto.
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
        // Si se subió audio nuevo, los campos url/mime/size se actualizan desde el fichero real.
        $form['audio_url']        = (string) $audioResult['url'];
        $form['audio_mime_type']  = (string) $audioResult['mime'];
        $form['audio_size_bytes'] = (string) $audioResult['size'];
        $id3Notice = $audioResult['id3Notice'];
    }

    // 4. Validación de audio post-subida.
    // Se comprueba aquí (y no en validateEpisodeForm) porque la URL y el tamaño
    // pueden provenir del fichero recién subido y no existir aún en el formulario inicial.
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
    // Si el usuario dejó imagen o autor en blanco, heredamos los del podcast.
    if ($form['image_url'] === '' && ($podcastDefaults['image_url'] ?? '') !== '') {
        $form['image_url'] = $podcastDefaults['image_url'];
    }
    if ($form['author'] === '' && ($podcastDefaults['author'] ?? '') !== '') {
        $form['author'] = $podcastDefaults['author'];
    }

    // 6. Reescritura ID3 en edición (sin subida nueva de audio).
    // Se activa si: estamos editando, no se subió audio nuevo, y además
    // se pulsó el botón manual O la opción global del podcast está activa.
    $shouldRewriteMetadata = $isEditing
        && !$audioResult['uploaded']
        && ($rewriteAudioMetadata || ($podcastDefaults['write_audio_metadata'] ?? 0) === 1);

    if ($shouldRewriteMetadata) {
        $id3Result = handleId3Rewrite($form['audio_url'], $form, $podcastDefaults);
        $id3Notice = $id3Result['id3Notice'];
        // Si la reescritura cambió el tamaño del fichero, actualizamos audio_size_bytes en BD.
        if ($id3Result['sizeBytes'] !== null) {
            $form['audio_size_bytes'] = (string) $id3Result['sizeBytes'];
        }
    }

    // 7. Link público: se genera al crear o al publicar por primera vez (draft → published).
    // En la primera publicación se regenera con la fecha real de publicación aunque ya
    // hubiera un link provisional basado en la fecha de creación del borrador.
    // En re-edición de episodios publicados se respeta el link existente para no romper URLs indexadas.
    if (!$isEditing || $isFirstPublish) {
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

    // Los campos opcionales se guardan como NULL en BD cuando están vacíos,
    // en lugar de cadena vacía, para que los JOINs y filtros funcionen correctamente.
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

    // Tras una creación exitosa reseteamos el formulario para que el usuario pueda
    // añadir otro episodio de inmediato. En edición conservamos los datos guardados.
    if (!$isEditing || $episodeId === null) {
        $form = episodeFormDefaults($podcastDefaults);
    }

    // 11. Regenerar feed.xml, sitemap.xml y limpiar caché.
    // El fallo de estos efectos secundarios no debe impedir que el episodio se haya guardado;
    // por eso capturamos Throwable y lo informamos como aviso en lugar de propagar la excepción.
    try {
        writePodcastFeedFile($pdo, dirname(__DIR__) . '/feed.xml', resolveFeedSelfHref($pdo));
        writePodcastSitemapFile($pdo, dirname(__DIR__) . '/sitemap.xml');
    } catch (Throwable $feedError) {
        $notice .= ' (Aviso: no se pudo regenerar feed.xml/sitemap.xml)';
    }
    if (!clearWebCache()) {
        $notice .= ' (Aviso: no se pudo limpiar completamente la caché)';
    }
    // id3Notice se adjunta al notice para que el usuario lo vea en un único bloque de aviso.
    if ($id3Notice !== '') {
        $notice .= ' (' . $id3Notice . ')';
    }

    return ['error' => '', 'notice' => $notice, 'id3Notice' => $id3Notice, 'form' => $form];
}
