<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';
require_once __DIR__ . '/id3_service.php';

/**
 * Devuelve las operaciones necesarias para aplicar una orientación EXIF.
 *
 * El ángulo usa la convención de GD: valores positivos giran en sentido
 * antihorario. El volteo se aplica después de la rotación.
 *
 * @return array{angle: int, flip: ?string}
 */
function getExifOrientationTransform(int $orientation): array
{
    return match ($orientation) {
        2 => ['angle' => 0, 'flip' => 'horizontal'],
        3 => ['angle' => 180, 'flip' => null],
        4 => ['angle' => 0, 'flip' => 'vertical'],
        5 => ['angle' => -90, 'flip' => 'horizontal'],
        6 => ['angle' => -90, 'flip' => null],
        7 => ['angle' => 90, 'flip' => 'horizontal'],
        8 => ['angle' => 90, 'flip' => null],
        default => ['angle' => 0, 'flip' => null],
    };
}

/**
 * Aplica físicamente la orientación EXIF de un JPEG subido desde cámara.
 *
 * Al recodificar el JPEG se elimina la etiqueta Orientation, evitando que el
 * navegador vuelva a girar una imagen cuyos píxeles ya están normalizados.
 */
function normalizeUploadedJpegOrientation(string $imagePath): bool
{
    if (
        !function_exists('exif_read_data')
        || !function_exists('imagecreatefromjpeg')
        || !function_exists('imagerotate')
        || !function_exists('imageflip')
        || !function_exists('imagejpeg')
    ) {
        // La subida sigue siendo compatible con instalaciones sin EXIF/GD.
        return true;
    }

    $exif = @exif_read_data($imagePath);
    $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    $transform = getExifOrientationTransform($orientation);

    if ($transform['angle'] === 0 && $transform['flip'] === null) {
        return true;
    }

    $image = @imagecreatefromjpeg($imagePath);
    if ($image === false) {
        return false;
    }

    if ($transform['angle'] !== 0) {
        $rotated = @imagerotate($image, $transform['angle'], 0);
        imagedestroy($image);
        if ($rotated === false) {
            return false;
        }
        $image = $rotated;
    }

    if ($transform['flip'] !== null) {
        $flipMode = $transform['flip'] === 'horizontal' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL;
        if (!@imageflip($image, $flipMode)) {
            imagedestroy($image);
            return false;
        }
    }

    try {
        $temporaryPath = $imagePath . '.orientation-' . bin2hex(random_bytes(4)) . '.tmp';
    } catch (Throwable) {
        imagedestroy($image);
        return false;
    }

    $written = @imagejpeg($image, $temporaryPath, 90);
    imagedestroy($image);

    if (!$written || !@rename($temporaryPath, $imagePath)) {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
        return false;
    }

    return true;
}

/**
 * Calcula un recorte centrado con la proporción del hero, sin ampliar la imagen.
 *
 * @return array{source_x: int, source_y: int, source_width: int, source_height: int, target_width: int, target_height: int}
 */
function calculateHeroImageCrop(
    int $sourceWidth,
    int $sourceHeight,
    int $maxWidth = 1720,
    int $maxHeight = 720
): array {
    if ($sourceWidth < 1 || $sourceHeight < 1 || $maxWidth < 1 || $maxHeight < 1) {
        throw new InvalidArgumentException('Las dimensiones de la imagen deben ser mayores que cero.');
    }

    $sourceRatio = $sourceWidth / $sourceHeight;
    $targetRatio = $maxWidth / $maxHeight;
    $cropWidth = $sourceWidth;
    $cropHeight = $sourceHeight;
    $sourceX = 0;
    $sourceY = 0;

    if ($sourceRatio > $targetRatio) {
        $cropWidth = max(1, (int) round($sourceHeight * $targetRatio));
        $sourceX = intdiv($sourceWidth - $cropWidth, 2);
    } elseif ($sourceRatio < $targetRatio) {
        $cropHeight = max(1, (int) round($sourceWidth / $targetRatio));
        $sourceY = intdiv($sourceHeight - $cropHeight, 2);
    }

    $scale = min(1.0, $maxWidth / $cropWidth, $maxHeight / $cropHeight);

    return [
        'source_x' => $sourceX,
        'source_y' => $sourceY,
        'source_width' => $cropWidth,
        'source_height' => $cropHeight,
        'target_width' => max(1, (int) round($cropWidth * $scale)),
        'target_height' => max(1, (int) round($cropHeight * $scale)),
    ];
}

/**
 * Recorta y comprime un hero subido. Usa WebP cuando GD lo soporta y JPEG
 * como alternativa, sin añadir dependencias ni impedir la subida si GD falla.
 */
function optimizeUploadedHeroImage(string $sourcePath): string
{
    if (
        !is_file($sourcePath)
        || !function_exists('getimagesize')
        || !function_exists('imagecreatefromstring')
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled')
        || !function_exists('imagejpeg')
    ) {
        return $sourcePath;
    }

    $metadata = @getimagesize($sourcePath);
    $sourceWidth = is_array($metadata) ? (int) ($metadata[0] ?? 0) : 0;
    $sourceHeight = is_array($metadata) ? (int) ($metadata[1] ?? 0) : 0;
    $fileSize = @filesize($sourcePath);

    // Evita agotar memoria al decodificar imágenes desproporcionadamente grandes.
    if (
        $sourceWidth < 1
        || $sourceHeight < 1
        || $sourceHeight > intdiv(16_000_000, $sourceWidth)
        || $fileSize === false
        || $fileSize > 24 * 1024 * 1024
    ) {
        return $sourcePath;
    }

    $sourceBytes = @file_get_contents($sourcePath);
    if ($sourceBytes === false) {
        return $sourcePath;
    }

    $sourceImage = @imagecreatefromstring($sourceBytes);
    if ($sourceImage === false) {
        return $sourcePath;
    }

    $crop = calculateHeroImageCrop($sourceWidth, $sourceHeight);
    $targetImage = @imagecreatetruecolor($crop['target_width'], $crop['target_height']);
    if ($targetImage === false) {
        imagedestroy($sourceImage);
        return $sourcePath;
    }

    $background = imagecolorallocate($targetImage, 255, 255, 255);
    imagefilledrectangle($targetImage, 0, 0, $crop['target_width'], $crop['target_height'], $background);
    $resampled = @imagecopyresampled(
        $targetImage,
        $sourceImage,
        0,
        0,
        $crop['source_x'],
        $crop['source_y'],
        $crop['target_width'],
        $crop['target_height'],
        $crop['source_width'],
        $crop['source_height']
    );
    imagedestroy($sourceImage);

    if (!$resampled) {
        imagedestroy($targetImage);
        return $sourcePath;
    }

    try {
        $randomSuffix = bin2hex(random_bytes(4));
    } catch (Throwable) {
        imagedestroy($targetImage);
        return $sourcePath;
    }

    $directory = dirname($sourcePath);
    $baseName = pathinfo($sourcePath, PATHINFO_FILENAME);
    $temporaryPath = $directory . '/.' . $baseName . '-optimized-' . $randomSuffix . '.tmp';
    $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
    $written = $extension === 'webp'
        ? @imagewebp($targetImage, $temporaryPath, 82)
        : @imagejpeg($targetImage, $temporaryPath, 82);

    // Algunas compilaciones exponen imagewebp() aunque el codificador falle.
    if (!$written && $extension === 'webp') {
        @unlink($temporaryPath);
        $extension = 'jpg';
        $written = @imagejpeg($targetImage, $temporaryPath, 82);
    }
    imagedestroy($targetImage);

    if (!$written) {
        @unlink($temporaryPath);
        return $sourcePath;
    }

    $geometryChanged = $crop['source_x'] !== 0
        || $crop['source_y'] !== 0
        || $crop['source_width'] !== $sourceWidth
        || $crop['source_height'] !== $sourceHeight
        || $crop['target_width'] !== $sourceWidth
        || $crop['target_height'] !== $sourceHeight;
    $optimizedSize = @filesize($temporaryPath);
    if (!$geometryChanged && $optimizedSize !== false && $optimizedSize >= $fileSize) {
        @unlink($temporaryPath);
        return $sourcePath;
    }

    $optimizedPath = $directory . '/' . $baseName . '.' . $extension;
    if (!@rename($temporaryPath, $optimizedPath)) {
        @unlink($temporaryPath);
        return $sourcePath;
    }

    if ($optimizedPath !== $sourcePath) {
        @unlink($sourcePath);
    }

    return $optimizedPath;
}

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
    return handleNamedImageUpload($fileData, $baseUrl, $imagesDir, 'episode-image');
}

/**
 * Maneja la subida y optimización de la imagen panorámica del hero.
 *
 * @return array{url: ?string, error: ?string}
 */
function handleHeroImageUpload(array $fileData, string $baseUrl, string $imagesDir): array
{
    return handleNamedImageUpload($fileData, $baseUrl, $imagesDir, 'podcast-hero', true);
}

/**
 * Maneja una subida de imagen con un nombre base específico para su contexto.
 *
 * @return array{url: ?string, error: ?string}
 */
function handleNamedImageUpload(
    array $fileData,
    string $baseUrl,
    string $imagesDir,
    string $fallbackName,
    bool $optimizeForHero = false
): array
{
    // UPLOAD_ERR_NO_FILE significa que el usuario no seleccionó fichero: no es un error.
    if ((int) ($fileData['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['url' => null, 'error' => null];
    }

    // Cualquier otro código distinto de UPLOAD_ERR_OK indica un fallo real de PHP.
    $uploadError = (int) ($fileData['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['url' => null, 'error' => 'No se pudo subir la imagen.'];
    }

    $tmpPath = (string) ($fileData['tmp_name'] ?? '');
    $originalName = (string) ($fileData['name'] ?? '');

    // Detectamos el MIME desde el contenido real del fichero, no desde el nombre
    // que envía el cliente, para evitar que se cuelen ficheros con extensión falsa.
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

    // Nombre seguro con timestamp + bytes aleatorios para evitar colisiones y path traversal.
    $fileName = buildSafeFileName($originalName, $fallbackName, $allowedImages[$mimeType]);

    // triple comprobación: is_dir → mkdir → is_dir para manejar condiciones de carrera.
    if (!is_dir($imagesDir) && !mkdir($imagesDir, 0755, true) && !is_dir($imagesDir)) {
        return ['url' => null, 'error' => 'No se pudo crear la carpeta /images.'];
    }

    $targetPath = $imagesDir . '/' . $fileName;

    // move_uploaded_file valida que el fichero proceda realmente de una subida HTTP.
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['url' => null, 'error' => 'No se pudo guardar la imagen subida.'];
    }

    if ($mimeType === 'image/jpeg' && !normalizeUploadedJpegOrientation($targetPath)) {
        @unlink($targetPath);
        return ['url' => null, 'error' => 'No se pudo corregir la orientación de la imagen subida.'];
    }

    if ($optimizeForHero) {
        $targetPath = optimizeUploadedHeroImage($targetPath);
        $fileName = basename($targetPath);
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
    // $empty centraliza la estructura de retorno "sin subida" para reutilizarla
    // como base en array_merge al devolver errores, evitando claves sueltas.
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

    // MIME desde el contenido real; resolveAudioExtension tiene fallback por extensión
    // para entornos donde el servidor reporta MIME poco fiables (ej. application/octet-stream).
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpPath);
    $audioExtension = resolveAudioExtension($mimeType, $originalName);

    if ($audioExtension === null) {
        // Incluimos MIME y extensión detectados para ayudar al usuario a diagnosticar.
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

    // Escribimos los tags ID3 justo después de mover el fichero, mientras tenemos
    // la ruta local. El fallo de ID3 es un aviso no bloqueante: el audio ya está subido.
    $id3Notice = '';
    if ($audioExtension === 'mp3' && ($podcastDefaults['write_audio_metadata'] ?? 0) === 1) {
        $id3Metadata = buildEpisodeId3Metadata($form, $podcastDefaults);
        if (!writeMp3Id3Tags($targetPath, $id3Metadata)) {
            $id3Notice = __('Aviso: no se pudieron escribir etiquetas ID3 en el MP3 subido.');
        }
    }

    // Leemos el tamaño DESPUÉS de escribir ID3 para que refleje el fichero final.
    $fileSize = filesize($targetPath);
    if ($fileSize === false) {
        return array_merge($empty, ['error' => 'No se pudo leer el tamaño del audio subido.']);
    }

    return [
        'url'       => rtrim($baseUrl, '/') . '/audios/' . $fileName,
        // Si el sistema reportó MIME vacío, usamos el valor por defecto del estándar RSS.
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
    // La opción global del podcast actúa como interruptor: si está desactivada
    // no se reescribe aunque el usuario haya pulsado el botón manual.
    if (($podcastDefaults['write_audio_metadata'] ?? 0) !== 1) {
        return [
            'id3Notice' => __('Aviso: activa primero "Escribir metadatos ID3 en MP3 al subir episodio" en Gestión Podcast.'),
            'sizeBytes' => null,
        ];
    }

    // Resolvemos la ruta local desde la URL pública (/audios/<fichero>).
    // Devuelve null si la URL no apunta a un fichero local en /audios/.
    $existingAudioPath = resolveLocalAudioPathFromUrl($audioUrl);
    if ($existingAudioPath === null) {
        return [
            'id3Notice' => __('Aviso: no se encontró un MP3 local en /audios/ para actualizar metadatos.'),
            'sizeBytes' => null,
        ];
    }

    // La reescritura manual solo tiene sentido para MP3; otros formatos no usan ID3.
    if (strtolower((string) pathinfo($existingAudioPath, PATHINFO_EXTENSION)) !== 'mp3') {
        return [
            'id3Notice' => __('Aviso: la actualización manual de metadatos solo está disponible para MP3.'),
            'sizeBytes' => null,
        ];
    }

    // Hash SHA-1 antes y después para detectar si los tags ya eran idénticos
    // y mostrar un mensaje diferenciado al usuario.
    $hashBefore = hash_file('sha1', $existingAudioPath) ?: null;
    $id3Metadata = buildEpisodeId3Metadata($form, $podcastDefaults);
    if (!writeMp3Id3Tags($existingAudioPath, $id3Metadata)) {
        return [
            'id3Notice' => 'Aviso: no se pudieron actualizar las etiquetas ID3 del MP3 existente.',
            'sizeBytes' => null,
        ];
    }

    // Tras reescribir, el tamaño puede cambiar (el tag ID3v2 se reemplaza completo).
    $fileSize = filesize($existingAudioPath);
    $sizeBytes = $fileSize !== false ? $fileSize : null;
    $hashAfter = hash_file('sha1', $existingAudioPath) ?: null;

    $id3Notice = ($hashBefore !== null && $hashAfter !== null && $hashBefore === $hashAfter)
        ? __('Metadatos ID3 revisados: el MP3 ya tenía esos valores.')
        : __('Metadatos ID3 actualizados en el MP3 existente.');

    return ['id3Notice' => $id3Notice, 'sizeBytes' => $sizeBytes];
}
