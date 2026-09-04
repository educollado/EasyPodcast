<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';

test('la grabadora permite pausar y reanudar sin contar el tiempo detenido', function () {
    $pageSource = file_get_contents(__DIR__ . '/../add_episode.php');
    $scriptSource = file_get_contents(__DIR__ . '/../assets/js/add_episode.js');
    $styleSource = file_get_contents(__DIR__ . '/../assets/css/admin-common.css');

    assert_true($pageSource !== false);
    assert_true($scriptSource !== false);
    assert_true($styleSource !== false);
    assert_contains('id="btn-pause"', $pageSource);
    assert_contains('mediaRecorder.pause();', $scriptSource);
    assert_contains('mediaRecorder.resume();', $scriptSource);
    assert_contains('elapsedRecordingTime += Date.now() - startTime;', $scriptSource);
    assert_contains("startTimer(false);", $scriptSource);
    assert_matches('/#btn-pause\s*\{[^}]*background:\s*#dc2626;/s', $styleSource);
});

test('todos los controles y mensajes de la grabadora están traducidos', function () {
    $messages = [
        'Tu navegador no soporta grabación de audio.',
        'No se pudo acceder al micrófono: ',
        'Codificando MP3…',
        'Codificando MP3 (grabación larga, puede tardar)…',
        'Error al decodificar el audio grabado.',
        'No se pudo reproducir la grabación.',
        'Error al subir: ',
        'Audio guardado correctamente.',
        'Error de red al subir el audio. Inténtalo de nuevo.',
        'Grabar desde micrófono',
        'Grabar',
        'Pausar',
        'Reanudar',
        'Parar',
        'Escuchar grabación',
        'Parar escucha',
        'Usar esta grabación',
        'Subiendo grabación…',
        'Grabación subida',
    ];
    $localeFiles = glob(__DIR__ . '/../locale/*.po') ?: [];

    assert_eq(8, count($localeFiles));
    foreach ($localeFiles as $localeFile) {
        $translations = i18n_parse_po($localeFile);
        foreach ($messages as $message) {
            assert_true(
                isset($translations[$message]) && $translations[$message] !== '',
                basename($localeFile) . ' no traduce: ' . $message
            );
        }
    }
});
