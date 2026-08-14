<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/backup_handler.php';

test('allowedMediaImportMimes rechaza extensiones ejecutables', function () {
    assert_eq([], allowedMediaImportMimes('images/payload.php'));
    assert_eq([], allowedMediaImportMimes('audios/config.phtml'));
    assert_eq(['image/jpeg'], allowedMediaImportMimes('images/cover.jpg'));
});

test('validateMediaZipEntry rechaza PHP dentro de images', function () {
    $error = validateMediaZipEntry('images/payload.php', 100, 100, 0);
    assert_contains('tipo de fichero no permitido', $error);
});

test('validateMediaZipEntry rechaza traversal y ficheros ocultos', function () {
    assert_true(validateMediaZipEntry('images/../payload.jpg', 100, 100, 0) !== '');
    assert_true(validateMediaZipEntry('images/.htaccess', 100, 100, 0) !== '');
});

test('validateMediaZipEntry limita tamaño individual y total', function () {
    assert_true(validateMediaZipEntry(
        'audios/large.mp3',
        MEDIA_IMPORT_MAX_FILE_BYTES + 1,
        MEDIA_IMPORT_MAX_FILE_BYTES + 1,
        0
    ) !== '');
    assert_true(validateMediaZipEntry(
        'audios/ok.mp3',
        2,
        2,
        MEDIA_IMPORT_MAX_TOTAL_BYTES - 1
    ) !== '');
});

test('validateMediaZipEntry detecta ratios de compresión peligrosos', function () {
    $error = validateMediaZipEntry('images/bomb.png', 1000000, 100, 0);
    assert_contains('compresión potencialmente peligrosa', $error);
});

