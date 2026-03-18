<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/episode_seo.php';

// =============================================================================
// buildEpisodeSeoData
// =============================================================================

test('buildEpisodeSeoData: podcast null y episode null → título "Podcast"', function () {
    $seo = buildEpisodeSeoData(null, null, '2024', '03', 'mi-episodio', '');
    assert_eq('Podcast', $seo['podcastTitle']);
});

test('buildEpisodeSeoData: episode null → pageTitle igual a podcastTitle', function () {
    $podcast = ['title' => 'Mi podcast', 'link' => 'https://example.com'];
    $seo = buildEpisodeSeoData($podcast, null, '2024', '03', 'mi-episodio', '');
    assert_eq('Mi podcast', $seo['pageTitle']);
});

test('buildEpisodeSeoData: episode presente → pageTitle con separador |', function () {
    $podcast = ['title' => 'Mi podcast', 'link' => 'https://example.com'];
    $episode = ['title' => 'Episodio 1', 'content' => ''];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'episodio-1', '');
    assert_eq('Episodio 1 | Mi podcast', $seo['pageTitle']);
});

test('buildEpisodeSeoData: episode null → episodeJsonLd = {}', function () {
    $seo = buildEpisodeSeoData(null, null, '2024', '03', 'mi-episodio', '');
    assert_eq('{}', $seo['episodeJsonLd']);
});

test('buildEpisodeSeoData: episode presente → JSON-LD contiene PodcastEpisode', function () {
    $podcast = ['title' => 'Mi podcast', 'link' => 'https://example.com'];
    $episode = ['title' => 'Ep 1', 'content' => 'Desc', 'pub_date' => '2024-03-01'];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep-1', '');
    assert_contains('"@type":"PodcastEpisode"', $seo['episodeJsonLd']);
    assert_contains('"name":"Ep 1"', $seo['episodeJsonLd']);
});

test('buildEpisodeSeoData: JSON-LD contiene partOfSeries con nombre del podcast', function () {
    $podcast = ['title' => 'Podcast X', 'link' => 'https://example.com'];
    $episode = ['title' => 'Ep', 'content' => ''];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep', '');
    assert_contains('PodcastSeries', $seo['episodeJsonLd']);
    assert_contains('Podcast X', $seo['episodeJsonLd']);
});

test('buildEpisodeSeoData: sin error → robotsContent index,follow', function () {
    $seo = buildEpisodeSeoData(null, null, '2024', '03', 'ep', '');
    assert_eq('index,follow', $seo['robotsContent']);
});

test('buildEpisodeSeoData: con error → robotsContent noindex,follow', function () {
    $seo = buildEpisodeSeoData(null, null, '2024', '03', 'ep', 'Capítulo no encontrado.');
    assert_eq('noindex,follow', $seo['robotsContent']);
});

test('buildEpisodeSeoData: canonicalUrl construida con year/month/slug', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildEpisodeSeoData($podcast, null, '2024', '03', 'mi-episodio', '');
    assert_eq('https://example.com/2024/03/mi-episodio', $seo['canonicalUrl']);
});

test('buildEpisodeSeoData: cover del episodio tiene preferencia sobre podcast', function () {
    $podcast = ['link' => 'https://example.com', 'image_url' => '/images/podcast.jpg'];
    $episode = ['title' => 'Ep', 'image_url' => '/images/episodio.jpg'];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep', '');
    assert_eq('/images/episodio.jpg', $seo['cover']);
});

test('buildEpisodeSeoData: cover cae en imagen del podcast cuando episodio no tiene', function () {
    $podcast = ['link' => 'https://example.com', 'image_url' => '/images/podcast.jpg'];
    $episode = ['title' => 'Ep', 'image_url' => ''];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep', '');
    assert_eq('/images/podcast.jpg', $seo['cover']);
});

test('buildEpisodeSeoData: metaDescription desde descripción del episodio', function () {
    $podcast = ['link' => 'https://example.com', 'description' => 'Desc podcast'];
    $episode = ['title' => 'Ep', 'content' => 'Desc episodio'];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep', '');
    assert_eq('Desc episodio', $seo['metaDescription']);
});

test('buildEpisodeSeoData: metaDescription cae en descripción del podcast si episodio no tiene', function () {
    $podcast = ['link' => 'https://example.com', 'description' => 'Desc podcast'];
    $episode = ['title' => 'Ep', 'content' => ''];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep', '');
    assert_eq('Desc podcast', $seo['metaDescription']);
});

test('buildEpisodeSeoData: metaDescription fallback cuando no hay descripción en ningún lado', function () {
    $podcast = ['title' => 'Podcast X', 'link' => 'https://example.com', 'description' => ''];
    $episode = ['title' => 'Ep', 'content' => ''];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep', '');
    assert_contains('Podcast X', $seo['metaDescription']);
});

test('buildEpisodeSeoData: ogImage usa favicon cuando no hay cover', function () {
    $podcast = ['link' => 'https://example.com', 'image_url' => ''];
    $seo = buildEpisodeSeoData($podcast, null, '2024', '03', 'ep', '');
    assert_contains('favicon.ico', $seo['ogImage']);
});

test('buildEpisodeSeoData: rssUrl apunta a /feed.xml', function () {
    $podcast = ['link' => 'https://example.com'];
    $seo = buildEpisodeSeoData($podcast, null, '2024', '03', 'ep', '');
    assert_eq('https://example.com/feed.xml', $seo['rssUrl']);
});

test('buildEpisodeSeoData: JSON-LD incluye associatedMedia cuando hay audio_url', function () {
    $podcast = ['title' => 'P', 'link' => 'https://example.com'];
    $episode = ['title' => 'Ep', 'content' => '', 'audio_url' => '/audios/ep.mp3'];
    $seo = buildEpisodeSeoData($podcast, $episode, '2024', '03', 'ep', '');
    assert_contains('associatedMedia', $seo['episodeJsonLd']);
    assert_contains('ep.mp3', $seo['episodeJsonLd']);
});

test('buildEpisodeSeoData: podcastAuthor usa owner_name con fallback a author', function () {
    $podcast = ['link' => 'https://example.com', 'owner_name' => '', 'author' => 'Autor Fallback'];
    $seo = buildEpisodeSeoData($podcast, null, '2024', '03', 'ep', '');
    assert_eq('Autor Fallback', $seo['podcastAuthor']);
});
