<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/episode_helpers.php';

function createImageUsageTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, image_url TEXT)');
    $pdo->exec('CREATE TABLE episodes (id INTEGER PRIMARY KEY, image_url TEXT)');

    return $pdo;
}

function episodeHelpersTestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

// =============================================================================
// resolveAudioExtension
// =============================================================================

test('resolveAudioExtension: MIME audio/mpeg → mp3', function () {
    assert_eq('mp3', resolveAudioExtension('audio/mpeg', 'audio.mp3'));
});

test('resolveAudioExtension: MIME audio/mp4 → m4a', function () {
    assert_eq('m4a', resolveAudioExtension('audio/mp4', 'audio.m4a'));
});

test('resolveAudioExtension: MIME video/mp4 → m4a (podcast en contenedor MP4)', function () {
    assert_eq('m4a', resolveAudioExtension('video/mp4', 'audio.mp4'));
});

test('resolveAudioExtension: MIME audio/ogg → ogg', function () {
    assert_eq('ogg', resolveAudioExtension('audio/ogg', 'audio.ogg'));
});

test('resolveAudioExtension: MIME desconocido con extensión .mp3 → mp3 (fallback extensión)', function () {
    assert_eq('mp3', resolveAudioExtension('application/octet-stream', 'archivo.mp3'));
});

test('resolveAudioExtension: MIME desconocido con extensión desconocida → null', function () {
    assert_null(resolveAudioExtension('application/octet-stream', 'archivo.exe'));
});

test('resolveAudioExtension: MIME y extensión desconocidos → null', function () {
    assert_null(resolveAudioExtension('text/plain', 'notas.txt'));
});

// =============================================================================
// normalizeDateTime
// =============================================================================

test('normalizeDateTime: cadena vacía → null', function () {
    assert_null(normalizeDateTime(''));
});

test('normalizeDateTime: null → null', function () {
    assert_null(normalizeDateTime(null));
});

test('normalizeDateTime: formato datetime-local (Y-m-d\\TH:i)', function () {
    assert_eq('2024-03-15 10:30:00', normalizeDateTime('2024-03-15T10:30'));
});

test('normalizeDateTime: formato SQL completo (Y-m-d H:i:s)', function () {
    assert_eq('2024-03-15 10:30:00', normalizeDateTime('2024-03-15 10:30:00'));
});

test('normalizeDateTime: formato H:i sin segundos (Y-m-d H:i)', function () {
    assert_eq('2024-03-15 10:30:00', normalizeDateTime('2024-03-15 10:30'));
});

test('normalizeDateTime: cadena inválida → null', function () {
    assert_null(normalizeDateTime('no-es-fecha'));
});

test('normalizeDateTime: sólo espacios → null', function () {
    assert_null(normalizeDateTime('   '));
});

// =============================================================================
// formatDateTimeLocal
// =============================================================================

test('formatDateTimeLocal: fecha válida devuelve formato Y-m-d\\TH:i', function () {
    assert_eq('2024-03-15T10:30', formatDateTimeLocal('2024-03-15 10:30:00'));
});

test('formatDateTimeLocal: valor vacío devuelve cadena vacía', function () {
    assert_eq('', formatDateTimeLocal(''));
});

// =============================================================================
// slugify
// =============================================================================

test('slugify: convierte espacios a guiones', function () {
    assert_eq('hola-mundo', slugify('hola mundo'));
});

test('slugify: convierte a minúsculas', function () {
    assert_eq('hola-mundo', slugify('Hola Mundo'));
});

test('slugify: cadena vacía → capitulo', function () {
    assert_eq('capitulo', slugify(''));
});

test('slugify: sólo espacios → capitulo', function () {
    assert_eq('capitulo', slugify('   '));
});

test('slugify: guiones múltiples se normalizan', function () {
    assert_eq('hola-mundo', slugify('hola---mundo'));
});

test('slugify: ya es slug válido → sin cambios', function () {
    assert_eq('ya-es-slug', slugify('ya-es-slug'));
});

test('slugify: caracteres especiales se eliminan', function () {
    assert_eq('episodio-1', slugify('¡Episodio #1!'));
});

// =============================================================================
// buildEpisodePublicLink
// =============================================================================

test('buildEpisodePublicLink: construye URL /YYYY/MM/slug', function () {
    assert_eq(
        'https://example.com/2024/03/mi-episodio',
        buildEpisodePublicLink('https://example.com', '2024-03-15 10:30:00', 'Mi Episodio')
    );
});

test('buildEpisodePublicLink: baseUrl con barra final se normaliza', function () {
    assert_eq(
        'https://example.com/2024/01/test',
        buildEpisodePublicLink('https://example.com/', '2024-01-01 00:00:00', 'Test')
    );
});

test('buildEpisodePublicLink: pubDate null usa tiempo actual (formato correcto)', function () {
    $result = buildEpisodePublicLink('https://example.com', null, 'Test');
    assert_matches('#^https://example\.com/\d{4}/\d{2}/test$#', $result);
});

// =============================================================================
// buildSafeFileName
// =============================================================================

test('buildSafeFileName: resultado contiene base y extensión correcta', function () {
    $result = buildSafeFileName('Mi Audio.mp3', 'audio', 'mp3');
    assert_matches('/^mi-audio-\d{14}-[0-9a-f]{8}\.mp3$/', $result);
});

test('buildSafeFileName: nombre vacío usa fallback', function () {
    $result = buildSafeFileName('', 'audio', 'mp3');
    assert_matches('/^audio-\d{14}-[0-9a-f]{8}\.mp3$/', $result);
});

test('buildSafeFileName: nombre con sólo caracteres especiales usa fallback', function () {
    $result = buildSafeFileName('!!!', 'audio', 'mp3');
    assert_matches('/^audio-\d{14}-[0-9a-f]{8}\.mp3$/', $result);
});

// =============================================================================
// isImageUrlInUse
// =============================================================================

test('isImageUrlInUse: protege la portada aunque ningún episodio la use', function () {
    if (!episodeHelpersTestHasSqliteDriver()) {
        return;
    }

    $pdo = createImageUsageTestPdo();
    $pdo->exec("INSERT INTO podcast (id, image_url) VALUES (1, '/images/cover.png')");

    assert_true(isImageUrlInUse($pdo, '/images/cover.png'));
});

test('isImageUrlInUse: detecta imágenes compartidas por otros episodios', function () {
    if (!episodeHelpersTestHasSqliteDriver()) {
        return;
    }

    $pdo = createImageUsageTestPdo();
    $pdo->exec("INSERT INTO episodes (id, image_url) VALUES (1, '/images/shared.png')");

    assert_true(isImageUrlInUse($pdo, '/images/shared.png'));
});

test('isImageUrlInUse: permite borrar una imagen sin referencias', function () {
    if (!episodeHelpersTestHasSqliteDriver()) {
        return;
    }

    $pdo = createImageUsageTestPdo();

    assert_true(!isImageUrlInUse($pdo, '/images/orphan.png'));
});

// =============================================================================
// probeLocalAudioDuration
// =============================================================================

test('probeLocalAudioDuration: calcula duración aproximada de un MP3 CBR local', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'ep-mp3-');
    if ($tmpBase === false) {
        throw new RuntimeException('No se pudo crear el MP3 temporal');
    }
    $filePath = $tmpBase . '.mp3';
    @rename($tmpBase, $filePath);

    try {
        $frameHeader = "\xFF\xFB\x90\x64"; // MPEG1 Layer III, 128 kbps, 44100 Hz, stereo
        $frameSize = 417;
        $frameCount = 231; // ~6,02 s
        $frameBody = str_repeat("\0", $frameSize - 4);
        $bytes = str_repeat($frameHeader . $frameBody, $frameCount);
        file_put_contents($filePath, $bytes);

        assert_eq('00:00:06', probeLocalAudioDuration($filePath));
    } finally {
        @unlink($filePath);
    }
});

test('probeLocalAudioDuration: ignora el tag ID3v2 inicial al calcular un MP3', function () {
    $tmpBase = tempnam(sys_get_temp_dir(), 'ep-mp3-id3-');
    if ($tmpBase === false) {
        throw new RuntimeException('No se pudo crear el MP3 temporal con ID3');
    }
    $filePath = $tmpBase . '.mp3';
    @rename($tmpBase, $filePath);

    try {
        $id3Header = 'ID3' . "\x03\x00\x00" . "\x00\x00\x00\x14" . str_repeat("\0", 20);
        $frameHeader = "\xFF\xFB\x90\x64";
        $frameSize = 417;
        $frameCount = 231; // ~6,02 s
        $frameBody = str_repeat("\0", $frameSize - 4);
        $bytes = $id3Header . str_repeat($frameHeader . $frameBody, $frameCount);
        file_put_contents($filePath, $bytes);

        assert_eq('00:00:06', probeLocalAudioDuration($filePath));
    } finally {
        @unlink($filePath);
    }
});

// =============================================================================
// generateGuid
// =============================================================================

test('generateGuid: empieza con ep- y tiene formato correcto', function () {
    $guid = generateGuid();
    assert_matches('/^ep-\d{14}-[0-9a-f]{16}$/', $guid);
});
