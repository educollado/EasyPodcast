<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/view_helpers.php';

// =============================================================================
// Funciones puras relacionadas con caché que podemos testear
// =============================================================================

// Test de helper functions relacionadas con cache

test('esc: escapa etiquetas HTML correctamente', function () {
    $result = esc('<script>alert("xss")</script>');
    assert_eq('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $result);
});

test('esc: escapa comillas simples y dobles', function () {
    $result = esc("test 'value' \"double\"");
    assert_contains('&quot;', $result);
    assert_contains('&#039;', $result);
});

// =============================================================================
// Test de funciones de view helpers que se usan en cache_management.php
// =============================================================================

test('slugify: convierte espacios a guiones', function () {
    $result = slugify('hola mundo');
    assert_eq('hola-mundo', $result);
});

test('slugify: convierte a minúsculas', function () {
    $result = slugify('Hola Mundo');
    assert_eq('hola-mundo', $result);
});

test('formatPublishedDate: fecha válida', function () {
    $result = formatPublishedDate('2024-03-15 10:30:00');
    assert_eq('15/03/2024 10:30', $result);
});

test('formatPublishedDate: cadena vacía', function () {
    $result = formatPublishedDate('');
    assert_eq('', $result);
});

test('formatPublishedDate: null', function () {
    $result = formatPublishedDate(null);
    assert_eq('', $result);
});
