<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/view_helpers.php';

// =============================================================================
// esc
// =============================================================================

test('esc: escapa etiquetas HTML', function () {
    assert_eq('&lt;script&gt;alert(1)&lt;/script&gt;', esc('<script>alert(1)</script>'));
});

test('esc: escapa comillas dobles y simples', function () {
    assert_eq('&quot;hola&quot; &#039;mundo&#039;', esc('"hola" \'mundo\''));
});

test('esc: escapa ampersand', function () {
    assert_eq('a &amp; b', esc('a & b'));
});

test('esc: cadena sin caracteres especiales pasa intacta', function () {
    assert_eq('texto normal', esc('texto normal'));
});

// =============================================================================
// slugify
// =============================================================================

test('slugify: convierte espacios a guiones', function () {
    assert_eq('hola-mundo', slugify('hola mundo'));
});

test('slugify: convierte a minúsculas', function () {
    assert_eq('hola-mundo', slugify('HOLA MUNDO'));
});

test('slugify: cadena vacía → capitulo', function () {
    assert_eq('capitulo', slugify(''));
});

test('slugify: sólo espacios → capitulo', function () {
    assert_eq('capitulo', slugify('   '));
});

test('slugify: caracteres especiales eliminados', function () {
    assert_eq('episodio-1', slugify('¡Episodio #1!'));
});

test('slugify: ya es slug válido → sin cambios', function () {
    assert_eq('un-slug-valido', slugify('un-slug-valido'));
});

// =============================================================================
// formatPublishedDate
// =============================================================================

test('formatPublishedDate: fecha válida en formato dd/mm/YYYY HH:MM', function () {
    assert_eq('15/03/2024 10:30', formatPublishedDate('2024-03-15 10:30:00'));
});

test('formatPublishedDate: cadena vacía → vacía', function () {
    assert_eq('', formatPublishedDate(''));
});

test('formatPublishedDate: null → vacío', function () {
    assert_eq('', formatPublishedDate(null));
});

// =============================================================================
// buildSizedImageUrl
// =============================================================================

test('buildSizedImageUrl: path relativo genera URL de variante correcta', function () {
    assert_eq(
        '/images/generated/cover-144x144.jpg',
        buildSizedImageUrl('/images/cover.jpg', 144)
    );
});

test('buildSizedImageUrl: URL absoluta mantiene esquema y host', function () {
    assert_eq(
        'https://example.com/images/generated/cover-256x256.png',
        buildSizedImageUrl('https://example.com/images/cover.png', 256)
    );
});

test('buildSizedImageUrl: cadena vacía → null', function () {
    assert_null(buildSizedImageUrl('', 144));
});

test('buildSizedImageUrl: archivo sin extensión → null', function () {
    assert_null(buildSizedImageUrl('/images/sinext', 144));
});

test('buildSizedImageUrl: tamaño se refleja en nombre de variante', function () {
    $url = buildSizedImageUrl('/images/podcast.jpg', 512);
    assert_eq('/images/generated/podcast-512x512.jpg', $url);
});

// =============================================================================
// renderTextWithLinks
// =============================================================================

test('renderTextWithLinks: texto plano se escapa correctamente', function () {
    $result = renderTextWithLinks('<hola>');
    assert_eq('&lt;hola&gt;', $result);
});

test('renderTextWithLinks: URL válida se convierte en enlace', function () {
    $result = renderTextWithLinks('Visita https://example.com para más info');
    assert_contains('<a href="https://example.com"', $result);
    assert_contains('rel="noopener noreferrer"', $result);
    assert_contains('target="_blank"', $result);
});

test('renderTextWithLinks: URL con punto final no incluye el punto en href', function () {
    $result = renderTextWithLinks('Ver https://example.com.');
    assert_contains('href="https://example.com"', $result);
    // El punto debe aparecer fuera del enlace
    assert_true(str_ends_with(strip_tags($result), '.'));
});

test('renderTextWithLinks: texto sin URL no genera enlaces', function () {
    $result = renderTextWithLinks('Sin enlaces aquí');
    assert_true(!str_contains($result, '<a '));
});

test('renderTextWithLinks: múltiples URLs generan múltiples enlaces', function () {
    $result = renderTextWithLinks('https://a.com y https://b.com');
    assert_eq(2, substr_count($result, '<a '));
});

// =============================================================================
// firstChars
// =============================================================================

test('firstChars: cadena vacía → text vacío sin truncado', function () {
    assert_eq(['text' => '', 'truncated' => false], firstChars('', 100));
});

test('firstChars: texto corto no se trunca', function () {
    $result = firstChars('Hola mundo', 20);
    assert_eq('Hola mundo', $result['text']);
    assert_true(!$result['truncated']);
});

test('firstChars: texto largo se trunca', function () {
    $result = firstChars('ABCDEFGHIJ', 5);
    assert_eq('ABCDE', $result['text']);
    assert_true($result['truncated']);
});

test('firstChars: espacios múltiples normalizados', function () {
    $result = firstChars("Hola  \n  mundo", 50);
    assert_eq('Hola mundo', $result['text']);
    assert_true(!$result['truncated']);
});

test('firstChars: texto de exactamente maxChars no se trunca', function () {
    $text = str_repeat('x', 10);
    $result = firstChars($text, 10);
    assert_eq($text, $result['text']);
    assert_true(!$result['truncated']);
});

test('firstChars: solo espacios → text vacío sin truncado', function () {
    assert_eq(['text' => '', 'truncated' => false], firstChars('   ', 100));
});
