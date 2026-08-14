<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/home_seo.php';

// =============================================================================
// buildHomeSeoData
// =============================================================================

test('buildHomeSeoData: podcast null → título por defecto "Podcast"', function () {
    $seo = buildHomeSeoData(null, 1, 1, '');
    assert_eq('Podcast', $seo['podcastTitle']);
});

test('buildHomeSeoData: podcast null → metaDescription fallback', function () {
    $seo = buildHomeSeoData(null, 1, 1, '');
    assert_eq('Podcast en EasyPodcast: episodios, reproductor y feed RSS.', $seo['metaDescription']);
});

test('buildHomeSeoData: podcast con descripción → metaDescription calculada', function () {
    $podcast = ['title' => 'Mi podcast', 'description' => 'Un podcast de prueba', 'link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_eq('Un podcast de prueba', $seo['metaDescription']);
});

test('buildHomeSeoData: page 1 sin error → robots index,follow', function () {
    $seo = buildHomeSeoData(null, 1, 1, '');
    assert_eq('index,follow', $seo['robotsContent']);
});

test('buildHomeSeoData: page > 1 → robots noindex,follow', function () {
    $seo = buildHomeSeoData(null, 2, 5, '');
    assert_eq('noindex,follow', $seo['robotsContent']);
});

test('buildHomeSeoData: error → robots noindex,follow', function () {
    $seo = buildHomeSeoData(null, 1, 1, 'Error de BD');
    assert_eq('noindex,follow', $seo['robotsContent']);
});

test('buildHomeSeoData: page 1 → prevUrl null', function () {
    $seo = buildHomeSeoData(null, 1, 3, '');
    assert_null($seo['prevUrl']);
});

test('buildHomeSeoData: page 2 → prevUrl apunta a /', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 2, 3, '');
    assert_eq('https://example.com/', $seo['prevUrl']);
});

test('buildHomeSeoData: page 3 → prevUrl apunta a /?page=2', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 3, 5, '');
    assert_eq('https://example.com/?page=2', $seo['prevUrl']);
});

test('buildHomeSeoData: última página → nextUrl null', function () {
    $seo = buildHomeSeoData(null, 3, 3, '');
    assert_null($seo['nextUrl']);
});

test('buildHomeSeoData: página intermedia → nextUrl apunta a página siguiente', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 2, 5, '');
    assert_eq('https://example.com/?page=3', $seo['nextUrl']);
});

test('buildHomeSeoData: canonicalUrl page 1 apunta a /', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 3, '');
    assert_eq('https://example.com/', $seo['canonicalUrl']);
});

test('buildHomeSeoData: canonicalUrl page > 1 incluye ?page=N', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 3, 5, '');
    assert_eq('https://example.com/?page=3', $seo['canonicalUrl']);
});

test('buildHomeSeoData: JSON-LD contiene @type PodcastSeries', function () {
    $podcast = ['title' => 'Mi podcast', 'link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_contains('"@type":"PodcastSeries"', $seo['seriesJsonLd']);
});

test('buildHomeSeoData: JSON-LD incluye nombre del podcast', function () {
    $podcast = ['title' => 'Podcast Ejemplo', 'link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_contains('"name":"Podcast Ejemplo"', $seo['seriesJsonLd']);
});

test('buildHomeSeoData: JSON-LD neutraliza cierres de script sin alterar el dato', function () {
    $podcast = ['title' => '</script><meta http-equiv="refresh">', 'link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');

    assert_true(!str_contains(strtolower($seo['seriesJsonLd']), '</script'));
    $decoded = json_decode($seo['seriesJsonLd'], true);
    assert_eq('</script><meta http-equiv="refresh">', $decoded['name'] ?? null);
});

test('buildHomeSeoData: JSON-LD incluye autor cuando owner_name está presente', function () {
    $podcast = ['title' => 'Mi podcast', 'owner_name' => 'Autor Test', 'link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_contains('"author"', $seo['seriesJsonLd']);
    assert_contains('Autor Test', $seo['seriesJsonLd']);
});

test('buildHomeSeoData: JSON-LD usa author como fallback si owner_name está vacío', function () {
    $podcast = ['title' => 'Mi podcast', 'owner_name' => '', 'author' => 'Autor Fallback', 'link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_contains('Autor Fallback', $seo['seriesJsonLd']);
});

test('buildHomeSeoData: rssUrl apunta a /feed.xml', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_eq('https://example.com/feed.xml', $seo['rssUrl']);
});

test('buildHomeSeoData: ogImage usa favicon cuando no hay imagen de podcast', function () {
    $podcast = ['link' => 'https://example.com', 'image_url' => ''];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_contains('favicon.ico', $seo['ogImage']);
});

test('buildHomeSeoData: ogImage usa imagen del podcast cuando existe', function () {
    $podcast = ['link' => 'https://example.com', 'image_url' => '/images/cover.jpg'];
    $seo = buildHomeSeoData($podcast, 1, 1, '');
    assert_contains('cover.jpg', $seo['ogImage']);
});
