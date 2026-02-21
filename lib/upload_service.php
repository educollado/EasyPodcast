<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';
require_once __DIR__ . '/id3_service.php';

/**
 * Maneja la subida opcional de imagen de portada del episodio.
 *
 * @param array  $fileData   Entrada de $_FILES['image_file']
 * @param string $baseUrl    URL base del podcast (sin slash final)
 * @param string $imagesDir  Ruta absoluta al directorio /images
 * @return array{url: ?string, error: ?string}
 */
function handleImageUpload(array $fileData, string $baseUrl, string $imagesDir): array
{
    if ((int) ($fileData['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['url' => null, 'error' => null];
    }

    $uploadError = (int) ($fileData['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['url' => null, 'error' => 'No se pudo subir la imagen del capítulo.'];
    }

    $tmpPath = (string) ($fileData['tmp_name'] ?? '');
    $originalName = (string) ($fileData['name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpPath);
    $allowedImages = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedImages[$mimeType])) {
        return ['url' => null, 'error' => 'La imagen debe ser jpg, png, gif o webp.'];
    }

    $fileName = buildSafeFileName($originalName, 'episode-image', $allowedImages[$mimeType]);
    if (!is_dir($imagesDir) && !mkdir($imagesDir, 0755, true) && !is_dir($imagesDir)) {
        return ['url' => null, 'error' => 'No se pudo crear la carpeta /images.'];
    }

    if (!move_uploaded_file($tmpPath, $imagesDir . '/' . $fileName)) {
        return ['url' => null, 'error' => 'No se pudo guardar la imagen subida.'];
    }

    return ['url' => rtrim($baseUrl, '/') . '/images/' . $fileName, 'error' => null];
}

/**
 * Maneja la subida opcional de audio del episodio.
 *
 * @param array  $fileData        Entrada de $_FILES['audio_file']
 * @param array  $form            Datos del formulario actuales
 * @param array  $podcastDefaults Valores por defecto del podcast
 * @param string $baseUrl         URL base del podcast (sin slash final)
 * @param string $audiosDir       Ruta absoluta al directorio /audios
 * @return array{url: ?string, mime: ?string, size: ?int, uploaded: bool, id3Notice: string, error: ?string}
 */
function handleAudioUpload(array $fileData, array $form, array $podcastDefaults, string $baseUrl, string $audiosDir): array
{
    $empty = ['url' => null, 'mime' => null, 'size' => null, 'uploaded' => false, 'id3Notice' => '', 'error' => null];

    if ((int) ($fileData['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $empty;
    }

    $uploadError = (int) ($fileData['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return array_merge($empty, ['error' => 'No se pudo subir el audio del capítulo.']);
    }

    $tmpPath = (string) ($fileData['tmp_name'] ?? '');
    $originalName = (string) ($fileData['name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpPath);
    $audioExtension = resolveAudioExtension($mimeType, $originalName);

    if ($audioExtension === null) {
        $detectedMime = $mimeType !== '' ? $mimeType : 'desconocido';
        $detectedExtension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($detectedExtension === '') {
            $detectedExtension = 'sin extensión';
        }
        return array_merge($empty, [
            'error' => 'El audio debe ser mp3, m4a, aac, ogg, wav o webm. MIME detectado: '
                . $detectedMime . '. Extensión detectada: ' . $detectedExtension . '.',
        ]);
    }

    $fileName = buildSafeFileName($originalName, 'episode-audio', $audioExtension);
    if (!is_dir($audiosDir) && !mkdir($audiosDir, 0755, true) && !is_dir($audiosDir)) {
        return array_merge($empty, ['error' => 'No se pudo crear la carpeta /audios.']);
    }

    $targetPath = $audiosDir . '/' . $fileName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return array_merge($empty, ['error' => 'No se pudo guardar el audio subido. Revisa upload_tmp_dir/open_basedir en PHP.']);
    }

    $id3Notice = '';
    if ($audioExtension === 'mp3' && ($podcastDefaults['write_audio_metadata'] ?? 0) === 1) {
        $id3Metadata = buildEpisodeId3Metadata($form, $podcastDefaults);
        if (!writeMp3Id3Tags($targetPath, $id3Metadata)) {
            $id3Notice = 'Aviso: no se pudieron escribir etiquetas ID3 en el MP3 subido.';
        }
    }

    $fileSize = filesize($targetPath);
    if ($fileSize === false) {
        return array_merge($empty, ['error' => 'No se pudo leer el tamaño del audio subido.']);
    }

    return [
        'url'       => rtrim($baseUrl, '/') . '/audios/' . $fileName,
        'mime'      => $mimeType !== '' ? $mimeType : 'audio/mpeg',
        'size'      => $fileSize,
        'uploaded'  => true,
        'id3Notice' => $id3Notice,
        'error'     => null,
    ];
}

/**
 * Reescribe los metadatos ID3 del MP3 existente (solo en modo edición sin subida nueva).
 *
 * @param string $audioUrl        URL pública del audio actual
 * @param array  $form            Datos del formulario
 * @param array  $podcastDefaults Valores por defecto del podcast
 * @return array{id3Notice: string, sizeBytes: ?int}
 */
function handleId3Rewrite(string $audioUrl, array $form, array $podcastDefaults): array
{
    if (($podcastDefaults['write_audio_metadata'] ?? 0) !== 1) {
        return [
            'id3Notice' => 'Aviso: activa primero "Escribir metadatos ID3 en MP3 al subir episodio" en Gestión Podcast.',
            'sizeBytes' => null,
        ];
    }

    $existingAudioPath = resolveLocalAudioPathFromUrl($audioUrl);
    if ($existingAudioPath === null) {
        return [
            'id3Notice' => 'Aviso: no se encontró un MP3 local en /audios/ para actualizar metadatos.',
            'sizeBytes' => null,
        ];
    }

    if (strtolower((string) pathinfo($existingAudioPath, PATHINFO_EXTENSION)) !== 'mp3') {
        return [
            'id3Notice' => 'Aviso: la actualización manual de metadatos solo está disponible para MP3.',
            'sizeBytes' => null,
        ];
    }

    $hashBefore = hash_file('sha1', $existingAudioPath) ?: null;
    $id3Metadata = buildEpisodeId3Metadata($form, $podcastDefaults);
    if (!writeMp3Id3Tags($existingAudioPath, $id3Metadata)) {
        return [
            'id3Notice' => 'Aviso: no se pudieron actualizar las etiquetas ID3 del MP3 existente.',
            'sizeBytes' => null,
        ];
    }

    $fileSize = filesize($existingAudioPath);
    $sizeBytes = $fileSize !== false ? $fileSize : null;
    $hashAfter = hash_file('sha1', $existingAudioPath) ?: null;

    $id3Notice = ($hashBefore !== null && $hashAfter !== null && $hashBefore === $hashAfter)
        ? 'Metadatos ID3 revisados: el MP3 ya tenía esos valores.'
        : 'Metadatos ID3 actualizados en el MP3 existente.';

    return ['id3Notice' => $id3Notice, 'sizeBytes' => $sizeBytes];
}
