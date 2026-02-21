<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';

// ---------------------------------------------------------------------------
// Normalizacion de texto para ID3
// ---------------------------------------------------------------------------

/**
 * Convierte texto UTF-8 a ISO-8859-1 con fallback ASCII para etiquetas ID3v1.
 * Usa iconv cuando está disponible; si no, elimina caracteres no ASCII.
 */
function toId3Latin1(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $trimmed);
        if ($converted !== false) {
            return $converted;
        }
    }

    return preg_replace('/[^\x20-\x7E]/', '', $trimmed) ?? '';
}

/**
 * Construye un campo de longitud fija para ID3v1, rellenado con bytes nulos.
 * Recorta el valor a $length caracteres Latin-1 y completa el resto con \0.
 */
function buildId3FixedField(string $value, int $length): string
{
    $latin1 = toId3Latin1($value);
    $trimmed = substr($latin1, 0, $length);

    return str_pad($trimmed, $length, "\0");
}

/**
 * Genera el campo de comentario para ID3v1 a partir de texto con HTML.
 * Elimina tags HTML y espacios extras antes de truncar a $maxLength en Latin-1.
 */
function summarizeId3Comment(string $value, int $maxLength): string
{
    $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));
    if ($plain === '') {
        return '';
    }

    return substr(toId3Latin1($plain), 0, $maxLength);
}

/**
 * Mapea los datos del formulario de episodio y defaults de podcast al array de metadatos ID3.
 * El campo 'image_path' resuelve la ruta local de la portada para el frame APIC.
 *
 * @return array{title:string, artist:string, album:string, year:string, comment:string, track:string, image_path:?string}
 */
function buildEpisodeId3Metadata(array $form, array $podcastDefaults): array
{
    $pubDateForTag = normalizeDateTime((string) ($form['pub_date'] ?? ''));
    $pubYear = '';
    if ($pubDateForTag !== null) {
        $pubYear = date('Y', (int) strtotime($pubDateForTag));
    }

    $episodeNumber = trim((string) ($form['episode_number'] ?? ''));
    $commentMaxLength = $episodeNumber !== '' ? 28 : 30;

    return [
        'title' => (string) ($form['title'] ?? ''),
        'artist' => (string) (($form['author'] ?? '') !== '' ? $form['author'] : ($podcastDefaults['author'] ?? '')),
        'album' => (string) ($podcastDefaults['title'] ?? ''),
        'year' => $pubYear,
        'comment' => summarizeId3Comment((string) ($form['description'] ?? ''), $commentMaxLength),
        'track' => $episodeNumber,
        'image_path' => resolveLocalImagePathFromUrl(
            (string) (($form['image_url'] ?? '') !== '' ? $form['image_url'] : ($podcastDefaults['image_url'] ?? ''))
        ),
    ];
}

// ---------------------------------------------------------------------------
// Utilidades binarias para cabeceras ID3v2 (syncsafe integer)
// ---------------------------------------------------------------------------

/**
 * Codifica un entero en formato syncsafe de 4 bytes (7 bits útiles por byte) para cabeceras ID3v2.
 */
function encodeId3SyncSafeSize(int $size): string
{
    return chr(($size >> 21) & 0x7F)
        . chr(($size >> 14) & 0x7F)
        . chr(($size >> 7) & 0x7F)
        . chr($size & 0x7F);
}

/**
 * Decodifica un tamaño syncsafe de 4 bytes de una cabecera ID3v2.
 * Devuelve null si alguno de los bytes tiene el bit 7 activo (formato inválido).
 */
function decodeId3SyncSafeSize(string $bytes): ?int
{
    if (strlen($bytes) !== 4) {
        return null;
    }

    $b1 = ord($bytes[0]);
    $b2 = ord($bytes[1]);
    $b3 = ord($bytes[2]);
    $b4 = ord($bytes[3]);
    if (($b1 & 0x80) || ($b2 & 0x80) || ($b3 & 0x80) || ($b4 & 0x80)) {
        return null;
    }

    return ($b1 << 21) | ($b2 << 14) | ($b3 << 7) | $b4;
}

// ---------------------------------------------------------------------------
// Constructores de frames ID3v2.3
// ---------------------------------------------------------------------------

/**
 * Construye un frame de texto ID3v2.3 genérico (TIT2, TPE1, TALB, TYER, TRCK, etc.).
 * El payload usa codificación UTF-8 (byte 0x03).
 */
function buildId3v23TextFrame(string $frameId, string $text): string
{
    $payload = chr(3) . trim($text); // UTF-8
    $size = strlen($payload);
    $header = $frameId
        . chr(($size >> 24) & 0xFF)
        . chr(($size >> 16) & 0xFF)
        . chr(($size >> 8) & 0xFF)
        . chr($size & 0xFF)
        . "\0\0";

    return $header . $payload;
}

/**
 * Construye un frame COMM (comentario) ID3v2.3 con idioma "spa" y descripción corta vacía.
 */
function buildId3v23CommentFrame(string $comment): string
{
    $payload = chr(3) . 'spa' . "\0" . trim($comment); // UTF-8, lang spa, shortdesc vacío
    $size = strlen($payload);
    $header = 'COMM'
        . chr(($size >> 24) & 0xFF)
        . chr(($size >> 16) & 0xFF)
        . chr(($size >> 8) & 0xFF)
        . chr($size & 0xFF)
        . "\0\0";

    return $header . $payload;
}

/**
 * Construye un frame APIC (Picture Attached) ID3v2.3 para incrustar la portada.
 * Solo acepta MIME image/jpeg, image/png, image/gif e image/webp.
 * Devuelve null si el fichero no existe, está vacío o su MIME no está permitido.
 */
function buildId3v23ApicFrame(string $imagePath): ?string
{
    $imageData = @file_get_contents($imagePath);
    if (!is_string($imageData) || $imageData === '') {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = strtolower((string) $finfo->file($imagePath));
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowed, true)) {
        return null;
    }

    // APIC: encoding(0) + mime + \0 + picture type(3 front cover) + description(\0) + image bytes.
    $payload = chr(0) . $mimeType . "\0" . chr(3) . "\0" . $imageData;
    $size = strlen($payload);
    $header = 'APIC'
        . chr(($size >> 24) & 0xFF)
        . chr(($size >> 16) & 0xFF)
        . chr(($size >> 8) & 0xFF)
        . chr($size & 0xFF)
        . "\0\0";

    return $header . $payload;
}

// ---------------------------------------------------------------------------
// Escritura ID3v2.3 e ID3v1.1
// ---------------------------------------------------------------------------

/**
 * Escribe un tag ID3v2.3 al inicio del MP3 preservando el audio original.
 * Descarta cualquier tag ID3v2 existente antes de escribir el nuevo.
 * Usa un fichero temporal + rename atómico para evitar corrupción parcial.
 *
 * @param array{title?:string, artist?:string, album?:string, year?:string, comment?:string, track?:string, image_path?:string} $metadata
 */
function writeId3v23Tag(string $filePath, array $metadata): bool
{
    // Leemos el fichero original para copiar su contenido despues del nuevo tag.
    $source = @fopen($filePath, 'rb');
    if ($source === false) {
        return false;
    }

    // Si ya habia ID3v2 al principio, calculamos el offset para saltarlo.
    $sourceOffset = 0;
    $header = fread($source, 10);
    if (is_string($header) && strlen($header) === 10 && strncmp($header, 'ID3', 3) === 0) {
        $tagSize = decodeId3SyncSafeSize(substr($header, 6, 4));
        if ($tagSize !== null) {
            $sourceOffset = 10 + $tagSize;
            $flags = ord($header[5]);
            if (($flags & 0x10) === 0x10) {
                $sourceOffset += 10; // footer presente
            }
        }
    }

    // Construimos solo frames con valor para no meter metadatos vacios.
    $frames = '';
    $title = trim((string) ($metadata['title'] ?? ''));
    $artist = trim((string) ($metadata['artist'] ?? ''));
    $album = trim((string) ($metadata['album'] ?? ''));
    $year = trim((string) ($metadata['year'] ?? ''));
    $comment = trim((string) ($metadata['comment'] ?? ''));
    $track = trim((string) ($metadata['track'] ?? ''));
    $imagePath = trim((string) ($metadata['image_path'] ?? ''));

    if ($title !== '') {
        $frames .= buildId3v23TextFrame('TIT2', $title);
    }
    if ($artist !== '') {
        $frames .= buildId3v23TextFrame('TPE1', $artist);
    }
    if ($album !== '') {
        $frames .= buildId3v23TextFrame('TALB', $album);
    }
    if ($year !== '') {
        $frames .= buildId3v23TextFrame('TYER', substr($year, 0, 4));
    }
    if ($track !== '') {
        $frames .= buildId3v23TextFrame('TRCK', $track);
    }
    if ($comment !== '') {
        $frames .= buildId3v23CommentFrame($comment);
    }
    if ($imagePath !== '') {
        $apic = buildId3v23ApicFrame($imagePath);
        if ($apic !== null) {
            $frames .= $apic;
        }
    }

    // Cabecera ID3v2.3 + payload de frames.
    $tag = 'ID3' . chr(3) . chr(0) . chr(0) . encodeId3SyncSafeSize(strlen($frames)) . $frames;
    // Escribimos en temporal y luego rename atomico para evitar corrupcion parcial.
    $tmpPath = dirname($filePath) . '/.' . basename($filePath) . '.id3tmp-' . bin2hex(random_bytes(4));
    $target = @fopen($tmpPath, 'wb');
    if ($target === false) {
        fclose($source);
        return false;
    }

    $ok = true;
    if (fwrite($target, $tag) !== strlen($tag)) {
        $ok = false;
    } elseif (fseek($source, $sourceOffset, SEEK_SET) !== 0) {
        $ok = false;
    } elseif (stream_copy_to_stream($source, $target) === false) {
        $ok = false;
    }

    fclose($source);
    fclose($target);

    if (!$ok) {
        @unlink($tmpPath);
        return false;
    }

    if (!@rename($tmpPath, $filePath)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}

/**
 * Escribe una etiqueta ID3v1.1 al final de un MP3, sin depender de binarios externos.
 * Reemplaza el TAG existente si lo hay para evitar duplicados.
 *
 * @param array{title?:string, artist?:string, album?:string, year?:string, comment?:string, track?:string} $metadata
 */
function writeId3v1Tag(string $filePath, array $metadata): bool
{
    $handle = @fopen($filePath, 'c+b');
    if ($handle === false) {
        return false;
    }

    try {
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            return false;
        }

        // Si ya existe un TAG ID3v1 al final, lo reemplazamos para evitar duplicados.
        if ($fileSize >= 128 && fseek($handle, -128, SEEK_END) === 0) {
            $existing = fread($handle, 128);
            if (is_string($existing) && strlen($existing) === 128 && strncmp($existing, 'TAG', 3) === 0) {
                if (!ftruncate($handle, $fileSize - 128)) {
                    return false;
                }
            }
        }

        $title = buildId3FixedField((string) ($metadata['title'] ?? ''), 30);
        $artist = buildId3FixedField((string) ($metadata['artist'] ?? ''), 30);
        $album = buildId3FixedField((string) ($metadata['album'] ?? ''), 30);
        $year = buildId3FixedField((string) ($metadata['year'] ?? ''), 4);

        $trackNumber = null;
        if (isset($metadata['track']) && ctype_digit((string) $metadata['track'])) {
            $trackInt = (int) $metadata['track'];
            if ($trackInt >= 1 && $trackInt <= 255) {
                $trackNumber = $trackInt;
            }
        }

        if ($trackNumber !== null) {
            $comment = buildId3FixedField((string) ($metadata['comment'] ?? ''), 28) . "\0" . chr($trackNumber);
        } else {
            $comment = buildId3FixedField((string) ($metadata['comment'] ?? ''), 30);
        }

        // 255 = genero desconocido en ID3v1.
        $tag = 'TAG'
            . $title
            . $artist
            . $album
            . $year
            . $comment
            . chr(255);

        if (strlen($tag) !== 128 || fseek($handle, 0, SEEK_END) !== 0) {
            return false;
        }

        return fwrite($handle, $tag) === 128;
    } finally {
        fclose($handle);
    }
}

/**
 * Escribe tags ID3v2.3 e ID3v1.1 en un MP3 para máxima compatibilidad.
 * Primero escribe ID3v2.3 (lectores modernos) y luego ID3v1.1 (compatibilidad legacy).
 */
function writeMp3Id3Tags(string $filePath, array $metadata): bool
{
    if (!writeId3v23Tag($filePath, $metadata)) {
        return false;
    }

    return writeId3v1Tag($filePath, $metadata);
}
