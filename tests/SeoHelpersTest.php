<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/seo_helpers.php';

// =============================================================================
// resolveSeoBaseUrl
// =============================================================================

test('resolveSeoBaseUrl: URL completa → esquema + host', function () {
    assert_eq('https://example.com', resolveSeoBaseUrl('https://example.com'));
});

test('resolveSeoBaseUrl: URL con path → sólo esquema + host', function () {
    assert_eq('https://example.com', resolveSeoBaseUrl('https://example.com/podcast/'));
});

test('resolveSeoBaseUrl: URL con puerto → incluye puerto', function () {
    assert_eq('http://localhost:8080', resolveSeoBaseUrl('http://localhost:8080'));
});

test('resolveSeoBaseUrl: esquema se normaliza a minúsculas', function () {
    assert_eq('https://example.com', resolveSeoBaseUrl('HTTPS://example.com'));
});

// =============================================================================
// toAbsoluteSeoUrl
// =============================================================================

test('toAbsoluteSeoUrl: URL ya absoluta no se modifica', function () {
    assert_eq(
        'https://other.com/page',
        toAbsoluteSeoUrl('https://other.com/page', 'https://example.com')
    );
});

test('toAbsoluteSeoUrl: path relativo se prefija con baseUrl', function () {
    assert_eq(
        'https://example.com/images/cover.jpg',
        toAbsoluteSeoUrl('/images/cover.jpg', 'https://example.com')
    );
});

test('toAbsoluteSeoUrl: path sin barra inicial también se prefija', function () {
    assert_eq(
        'https://example.com/images/cover.jpg',
        toAbsoluteSeoUrl('images/cover.jpg', 'https://example.com')
    );
});

test('toAbsoluteSeoUrl: cadena vacía → baseUrl sin barra final', function () {
    assert_eq('https://example.com', toAbsoluteSeoUrl('', 'https://example.com/'));
});

test('toAbsoluteSeoUrl: baseUrl con barra final no genera doble barra', function () {
    $result = toAbsoluteSeoUrl('/page', 'https://example.com/');
    assert_eq('https://example.com/page', $result);
});

// =============================================================================
// compactMetaText
// =============================================================================

test('compactMetaText: cadena vacía → vacía', function () {
    assert_eq('', compactMetaText(''));
});

test('compactMetaText: elimina etiquetas HTML', function () {
    assert_eq('Hola mundo', compactMetaText('<p>Hola <b>mundo</b></p>'));
});

test('compactMetaText: colapsa espacios múltiples', function () {
    assert_eq('Hola mundo', compactMetaText("Hola   \n  mundo"));
});

test('compactMetaText: texto corto no se trunca', function () {
    $text = 'Texto corto';
    assert_eq($text, compactMetaText($text, 160));
});

test('compactMetaText: texto largo se trunca con puntos suspensivos', function () {
    $long = str_repeat('a', 200);
    $result = compactMetaText($long, 160);
    assert_true(str_ends_with($result, '...'));
    assert_true(strlen($result) <= 163); // 160 chars + '...'
});

test('compactMetaText: maxChars personalizado respetado', function () {
    $text  = str_repeat('x', 80);
    $result = compactMetaText($text, 50);
    assert_true(str_ends_with($result, '...'));
});

test('compactMetaText: texto de exactamente maxChars no se trunca', function () {
    $text = str_repeat('a', 160);
    assert_eq($text, compactMetaText($text, 160));
});
