<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers generales de episodio: nombres de fichero, fechas, slugs y rutas
// ---------------------------------------------------------------------------

require_once __DIR__ . '/view_helpers.php';

/**
 * Genera nombres de fichero seguros y deterministas con timestamp + sufijo aleatorio.
 * Elimina caracteres no ASCII del nombre original para evitar path traversal.
 */
function buildSafeFileName(string $originalName, string $fallbackBase, string $extension): string
{
    $base = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)));
    $base = trim($base, '-');
    if ($base === '') {
        $base = $fallbackBase;
    }

    return $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
}

/**
 * Resuelve formatos de audio aceptados por MIME (con fallback por extensión).
 * Devuelve la extensión normalizada o null si el formato no está permitido.
 */
function resolveAudioExtension(string $mimeType, string $originalName): ?string
{
    $allowedAudios = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/x-mpeg' => 'mp3',
        'audio/x-mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'video/mp4' => 'm4a',
        'application/mp4' => 'm4a',
        'application/x-mpegurl' => 'mp3',
        'audio/aac' => 'aac',
        'audio/x-aac' => 'aac',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/vorbis' => 'ogg',
        'audio/wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/x-wav' => 'wav',
        'application/wav' => 'wav',
        'audio/webm' => 'webm',
    ];

    if (isset($allowedAudios[$mimeType])) {
        return $allowedAudios[$mimeType];
    }

    // Fallback por extensión porque algunos entornos reportan MIME poco fiables.
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = [
        'mp3' => 'mp3',
        'm4a' => 'm4a',
        'aac' => 'aac',
        'ogg' => 'ogg',
        'wav' => 'wav',
        'webm' => 'webm',
    ];
    if (isset($allowedExtensions[$extension])) {
        return $allowedExtensions[$extension];
    }

    return null;
}

/**
 * Formatea una duración en segundos al formato HH:MM:SS.
 */
function formatEpisodeDurationFromSeconds(int $seconds): string
{
    if ($seconds < 0) {
        return '';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
}

/**
 * Decodifica un entero syncsafe de 4 bytes (usado por ID3v2).
 */
function decodeSyncSafeInteger(string $bytes): ?int
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

/**
 * Devuelve el tamaño del tag ID3v2 inicial para saltarlo al analizar un MP3.
 */
function leadingId3v2TagSize($stream): int
{
    if (!is_resource($stream)) {
        return 0;
    }

    if (@fseek($stream, 0) !== 0) {
        return 0;
    }

    $header = fread($stream, 10);
    if (!is_string($header) || strlen($header) !== 10 || strncmp($header, 'ID3', 3) !== 0) {
        return 0;
    }

    $size = decodeSyncSafeInteger(substr($header, 6, 4));
    if ($size === null) {
        return 0;
    }

    $total = 10 + $size;
    $flags = ord($header[5]);
    if (($flags & 0x10) === 0x10) {
        $total += 10;
    }

    return $total;
}

/**
 * Parsea una cabecera MPEG de 4 bytes y devuelve sus metadatos si es válida.
 *
 * @return array{bitrate_kbps:int,sample_rate:int,samples_per_frame:int,frame_length:int,has_crc:bool,is_mono:bool,layer:int,version:int}|null
 */
function parseMp3FrameHeader(string $header): ?array
{
    if (strlen($header) !== 4) {
        return null;
    }

    $b1 = ord($header[0]);
    $b2 = ord($header[1]);
    $b3 = ord($header[2]);
    $b4 = ord($header[3]);

    if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) {
        return null;
    }

    $versionBits = ($b2 >> 3) & 0x03;
    $layerBits = ($b2 >> 1) & 0x03;
    $bitrateIndex = ($b3 >> 4) & 0x0F;
    $sampleRateIndex = ($b3 >> 2) & 0x03;
    $padding = ($b3 >> 1) & 0x01;
    $channelMode = ($b4 >> 6) & 0x03;

    if ($versionBits === 0x01 || $layerBits === 0x00 || $bitrateIndex === 0x00 || $bitrateIndex === 0x0F || $sampleRateIndex === 0x03) {
        return null;
    }

    $version = match ($versionBits) {
        0x03 => 1,
        0x02 => 2,
        0x00 => 25,
        default => 0,
    };
    if ($version === 0) {
        return null;
    }

    $layer = match ($layerBits) {
        0x03 => 1,
        0x02 => 2,
        0x01 => 3,
        default => 0,
    };
    if ($layer === 0) {
        return null;
    }

    $sampleRates = [
        1 => [44100, 48000, 32000],
        2 => [22050, 24000, 16000],
        25 => [11025, 12000, 8000],
    ];

    $bitrateTables = [
        'V1L1' => [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
        'V1L2' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
        'V1L3' => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        'V2L1' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
        'V2L2' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        'V2L3' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];

    $sampleRate = $sampleRates[$version][$sampleRateIndex] ?? 0;
    if ($sampleRate <= 0) {
        return null;
    }

    $tableKey = ($version === 1 ? 'V1' : 'V2') . 'L' . $layer;
    $bitrateKbps = $bitrateTables[$tableKey][$bitrateIndex] ?? 0;
    if ($bitrateKbps <= 0) {
        return null;
    }

    $samplesPerFrame = match ($layer) {
        1 => 384,
        2 => 1152,
        3 => $version === 1 ? 1152 : 576,
        default => 0,
    };
    if ($samplesPerFrame <= 0) {
        return null;
    }

    if ($layer === 1) {
        $frameLength = (int) floor(((12 * $bitrateKbps * 1000) / $sampleRate + $padding) * 4);
    } elseif ($layer === 3 && $version !== 1) {
        $frameLength = (int) floor((72 * $bitrateKbps * 1000) / $sampleRate + $padding);
    } else {
        $frameLength = (int) floor((144 * $bitrateKbps * 1000) / $sampleRate + $padding);
    }

    if ($frameLength <= 4) {
        return null;
    }

    return [
        'bitrate_kbps' => $bitrateKbps,
        'sample_rate' => $sampleRate,
        'samples_per_frame' => $samplesPerFrame,
        'frame_length' => $frameLength,
        'has_crc' => ($b2 & 0x01) === 0,
        'is_mono' => $channelMode === 0x03,
        'layer' => $layer,
        'version' => $version,
    ];
}

/**
 * Intenta obtener la duración de un MP3 local sin depender de herramientas externas.
 */
function probeMp3Duration(string $filePath): string
{
    $fileSize = @filesize($filePath);
    if ($fileSize === false || $fileSize <= 0) {
        return '';
    }

    $stream = @fopen($filePath, 'rb');
    if ($stream === false) {
        return '';
    }

    try {
        $startOffset = leadingId3v2TagSize($stream);
        if (@fseek($stream, $startOffset) !== 0) {
            return '';
        }

        $scanLength = min(max(0, $fileSize - $startOffset), 65536);
        $buffer = $scanLength > 0 ? fread($stream, $scanLength) : '';
        if (!is_string($buffer) || strlen($buffer) < 4) {
            return '';
        }

        $frameInfo = null;
        $frameOffset = null;
        $bufferLength = strlen($buffer);
        for ($i = 0; $i <= $bufferLength - 4; $i++) {
            $candidate = parseMp3FrameHeader(substr($buffer, $i, 4));
            if ($candidate !== null) {
                $frameInfo = $candidate;
                $frameOffset = $startOffset + $i;
                break;
            }
        }

        if ($frameInfo === null || $frameOffset === null) {
            return '';
        }

        if ($fileSize >= 128 && @fseek($stream, -128, SEEK_END) === 0) {
            $id3v1Header = fread($stream, 3);
            if ($id3v1Header === 'TAG') {
                $fileSize -= 128;
            }
        }

        if ($frameInfo['layer'] === 3) {
            $sideInfoSize = match ([$frameInfo['version'], $frameInfo['is_mono']]) {
                [1, true] => 17,
                [1, false] => 32,
                [2, true], [25, true] => 9,
                [2, false], [25, false] => 17,
                default => 0,
            };

            $xingOffset = $frameOffset + 4 + ($frameInfo['has_crc'] ? 2 : 0) + $sideInfoSize;
            if (@fseek($stream, $xingOffset) === 0) {
                $xingMarker = fread($stream, 4);
                if ($xingMarker === 'Xing' || $xingMarker === 'Info') {
                    $flagsBytes = fread($stream, 4);
                    if (is_string($flagsBytes) && strlen($flagsBytes) === 4) {
                        $flags = unpack('Nflags', $flagsBytes);
                        $flagsValue = (int) ($flags['flags'] ?? 0);
                        if (($flagsValue & 0x1) === 0x1) {
                            $framesBytes = fread($stream, 4);
                            if (is_string($framesBytes) && strlen($framesBytes) === 4) {
                                $frames = unpack('Nframes', $framesBytes);
                                $frameCount = (int) ($frames['frames'] ?? 0);
                                if ($frameCount > 0) {
                                    $seconds = (int) round(($frameCount * $frameInfo['samples_per_frame']) / $frameInfo['sample_rate']);
                                    return formatEpisodeDurationFromSeconds($seconds);
                                }
                            }
                        }
                    }
                }
            }
        }

        $audioBytes = $fileSize - $frameOffset;
        if ($audioBytes <= 0 || $frameInfo['bitrate_kbps'] <= 0) {
            return '';
        }

        $seconds = (int) round(($audioBytes * 8) / ($frameInfo['bitrate_kbps'] * 1000));
        return formatEpisodeDurationFromSeconds($seconds);
    } finally {
        fclose($stream);
    }
}

/**
 * Intenta obtener la duración de un WAV local usando su cabecera RIFF.
 */
function probeWavDuration(string $filePath): string
{
    $stream = @fopen($filePath, 'rb');
    if ($stream === false) {
        return '';
    }

    try {
        $header = fread($stream, 12);
        if (!is_string($header) || strlen($header) !== 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
            return '';
        }

        $byteRate = 0;
        $dataSize = 0;

        while (!feof($stream)) {
            $chunkHeader = fread($stream, 8);
            if (!is_string($chunkHeader) || strlen($chunkHeader) !== 8) {
                break;
            }

            $chunkId = substr($chunkHeader, 0, 4);
            $chunkSizeData = unpack('Vsize', substr($chunkHeader, 4, 4));
            $chunkSize = (int) ($chunkSizeData['size'] ?? 0);
            if ($chunkSize < 0) {
                return '';
            }

            if ($chunkId === 'fmt ') {
                $fmtData = fread($stream, $chunkSize);
                if (is_string($fmtData) && strlen($fmtData) >= 12) {
                    $byteRateData = unpack('Vbyte_rate', substr($fmtData, 8, 4));
                    $byteRate = (int) ($byteRateData['byte_rate'] ?? 0);
                }
            } elseif ($chunkId === 'data') {
                $dataSize = $chunkSize;
                if (@fseek($stream, $chunkSize, SEEK_CUR) !== 0) {
                    break;
                }
            } else {
                if (@fseek($stream, $chunkSize, SEEK_CUR) !== 0) {
                    break;
                }
            }

            if (($chunkSize % 2) === 1) {
                @fseek($stream, 1, SEEK_CUR);
            }

            if ($byteRate > 0 && $dataSize > 0) {
                break;
            }
        }

        if ($byteRate <= 0 || $dataSize <= 0) {
            return '';
        }

        $seconds = (int) round($dataSize / $byteRate);
        return formatEpisodeDurationFromSeconds($seconds);
    } finally {
        fclose($stream);
    }
}

/**
 * Obtiene la duración de un audio local cuando el formato es soportado.
 */
function probeLocalAudioDuration(string $filePath): string
{
    if (!is_file($filePath)) {
        return '';
    }

    $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

    return match ($extension) {
        'mp3' => probeMp3Duration($filePath),
        'wav' => probeWavDuration($filePath),
        default => '',
    };
}

/**
 * Normaliza varios formatos de fecha UI/API a datetime SQL (Y-m-d H:i:s).
 * Acepta datetime-local HTML5 (Y-m-d\TH:i), SQL y cadenas libres.
 * Devuelve null si el valor está vacío o no es parseable.
 */
function normalizeDateTime(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $formats = ['Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * Convierte datetime almacenado al formato de <input type="datetime-local"> (Y-m-d\TH:i).
 * Devuelve cadena vacía si el valor es nulo, vacío o no parseable.
 */
function formatDateTimeLocal(?string $value): string
{
    $normalized = normalizeDateTime($value);
    if ($normalized === null) {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized);
    if (!$dt instanceof DateTimeImmutable) {
        return '';
    }

    return $dt->format('Y-m-d\\TH:i');
}

/**
 * Genera un GUID único para el episodio cuando el usuario no lo proporciona.
 * Formato: ep-YYYYmmddHHiiss-<8 hex aleatorios>.
 */
function generateGuid(): string
{
    return 'ep-' . date('YmdHis') . '-' . bin2hex(random_bytes(8));
}

/**
 * Construye la URL pública del episodio en formato /YYYY/MM/slug.
 * Si pubDate no es válida, usa la fecha actual como fallback.
 */
function buildEpisodePublicLink(string $baseUrl, ?string $pubDate, string $title): string
{
    $normalized = normalizeDateTime($pubDate);
    $ts = $normalized !== null ? strtotime($normalized) : false;
    if ($ts === false) {
        $ts = time();
    }

    return rtrim($baseUrl, '/') . '/'
        . date('Y', $ts) . '/'
        . date('m', $ts) . '/'
        . slugify($title);
}

/**
 * Resuelve la ruta local a /audios/<fichero> partiendo de una URL pública.
 * Se usa para poder reescribir metadatos ID3 sobre el fichero físico existente.
 * Devuelve null si la URL no apunta a un fichero local en /audios/ o no existe.
 */
function resolveLocalAudioPathFromUrl(string $audioUrl): ?string
{
    $path = parse_url(trim($audioUrl), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    if (!preg_match('#/audios/([^/]+)$#', $path, $matches)) {
        return null;
    }

    $fileName = basename((string) $matches[1]);
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }

    $localPath = __DIR__ . '/../audios/' . $fileName;
    if (!is_file($localPath)) {
        return null;
    }

    return $localPath;
}

/**
 * Devuelve la duración almacenada o, si falta, intenta calcularla desde el audio local.
 */
function resolveEpisodeDuration(string $duration, string $audioUrl): string
{
    $duration = trim($duration);
    if ($duration !== '') {
        return $duration;
    }

    $localAudioPath = resolveLocalAudioPathFromUrl($audioUrl);
    if ($localAudioPath === null) {
        return '';
    }

    return probeLocalAudioDuration($localAudioPath);
}

/**
 * Resuelve la ruta local de un fichero de imagen a partir de su URL relativa o absoluta.
 * Verifica que la ruta resuelta no escape de la raíz del proyecto (protección path traversal).
 * Devuelve null si la ruta no existe como fichero o queda fuera del proyecto.
 */
function resolveLocalImagePathFromUrl(string $imageUrl): ?string
{
    $raw = trim($imageUrl);
    if ($raw === '') {
        return null;
    }

    $parsedPath = (string) parse_url($raw, PHP_URL_PATH);
    $candidate = $parsedPath !== '' ? $parsedPath : $raw;

    if ($candidate === '') {
        return null;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }

    if ($candidate[0] === '/') {
        $candidate = $projectRoot . $candidate;
    } else {
        $candidate = $projectRoot . '/' . $candidate;
    }

    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return null;
    }

    if (strpos($real, $projectRoot . DIRECTORY_SEPARATOR) !== 0 && $real !== $projectRoot) {
        return null;
    }

    return $real;
}
