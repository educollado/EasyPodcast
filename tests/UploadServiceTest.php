<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/upload_service.php';

test('handleNamedImageUpload: no seleccionar hero no produce error', function () {
    $result = handleNamedImageUpload(
        ['error' => UPLOAD_ERR_NO_FILE],
        'https://example.com',
        sys_get_temp_dir(),
        'podcast-hero'
    );

    assert_null($result['url']);
    assert_null($result['error']);
});

test('getExifOrientationTransform: orientación normal no transforma', function () {
    assert_eq(['angle' => 0, 'flip' => null], getExifOrientationTransform(1));
});

test('getExifOrientationTransform: orientación 6 corrige giro a la izquierda', function () {
    assert_eq(['angle' => -90, 'flip' => null], getExifOrientationTransform(6));
});

test('getExifOrientationTransform: orientación 8 corrige giro a la derecha', function () {
    assert_eq(['angle' => 90, 'flip' => null], getExifOrientationTransform(8));
});

test('getExifOrientationTransform: soporta orientaciones reflejadas', function () {
    assert_eq(['angle' => 0, 'flip' => 'horizontal'], getExifOrientationTransform(2));
    assert_eq(['angle' => 0, 'flip' => 'vertical'], getExifOrientationTransform(4));
    assert_eq(['angle' => -90, 'flip' => 'horizontal'], getExifOrientationTransform(5));
    assert_eq(['angle' => 90, 'flip' => 'horizontal'], getExifOrientationTransform(7));
});

test('getExifOrientationTransform: orientación 3 gira 180 grados', function () {
    assert_eq(['angle' => 180, 'flip' => null], getExifOrientationTransform(3));
});

test('getExifOrientationTransform: valor desconocido no transforma', function () {
    assert_eq(['angle' => 0, 'flip' => null], getExifOrientationTransform(99));
});
