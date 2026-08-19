<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/upload_service.php';

test('handleHeroImageUpload: no seleccionar hero no produce error', function () {
    $result = handleHeroImageUpload(
        ['error' => UPLOAD_ERR_NO_FILE],
        'https://example.com',
        sys_get_temp_dir()
    );

    assert_null($result['url']);
    assert_null($result['error']);
});

test('calculateHeroImageCrop: recorta una imagen horizontal desde el centro', function () {
    $crop = calculateHeroImageCrop(4000, 2000);
    assert_eq(4000, $crop['source_width']);
    assert_eq(1674, $crop['source_height']);
    assert_eq(163, $crop['source_y']);
    assert_eq(1720, $crop['target_width']);
    assert_eq(720, $crop['target_height']);
});

test('calculateHeroImageCrop: recorta una imagen vertical desde el centro', function () {
    $crop = calculateHeroImageCrop(1200, 2000);
    assert_eq(1200, $crop['source_width']);
    assert_eq(502, $crop['source_height']);
    assert_eq(749, $crop['source_y']);
    assert_eq(1200, $crop['target_width']);
    assert_eq(502, $crop['target_height']);
});

test('calculateHeroImageCrop: no amplía imágenes pequeñas', function () {
    $crop = calculateHeroImageCrop(860, 360);
    assert_eq(860, $crop['target_width']);
    assert_eq(360, $crop['target_height']);
});

test('calculateHeroImageCrop: rechaza dimensiones inválidas', function () {
    $thrown = false;
    try {
        calculateHeroImageCrop(0, 720);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    assert_true($thrown, 'Se esperaba InvalidArgumentException para dimensiones inválidas.');
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
