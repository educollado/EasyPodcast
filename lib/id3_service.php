<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';

// Convierte texto UTF-8 a ISO-8859-1 con fallback simple para ID3v1.
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

function buildId3FixedField(string $value, int $length): string
{
    $latin1 = toId3Latin1($value);
    $trimmed = substr($latin1, 0, $length);

    return str_pad($trimmed, $length, "\0");
}

function summarizeId3Comment(string $value, int $maxLength): string
{
    $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));
    if ($plain === '') {
        return '';
    }

    return substr(toId3Latin1($plain), 0, $maxLength);
}

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

function encodeId3SyncSafeSize(int $size): string
{
    return chr(($size >> 21) & 0x7F)
        . chr(($size >> 14) & 0x7F)
        . chr(($size >> 7) & 0x7F)
        . chr($size & 0x7F);
}

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

function writeId3v23Tag(string $filePath, array $metadata): bool
{
    $source = @fopen($filePath, 'rb');
    if ($source === false) {
        return false;
    }

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

    $tag = 'ID3' . chr(3) . chr(0) . chr(0) . encodeId3SyncSafeSize(strlen($frames)) . $frames;
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

// Escribe una etiqueta ID3v1.1 al final de un MP3 sin depender de binarios externos.
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

        // Si ya existe un TAG ID3v1 al final, se reemplaza para evitar duplicados.
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

        // 255 = género desconocido en ID3v1.
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

function writeMp3Id3Tags(string $filePath, array $metadata): bool
{
    if (!writeId3v23Tag($filePath, $metadata)) {
        return false;
    }

    return writeId3v1Tag($filePath, $metadata);
}
