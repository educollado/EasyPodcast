<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/podcast_context.php';

test('normalizePodcastSlug translitera y normaliza el directorio', function () {
    assert_eq('aratos-tecnologia', normalizePodcastSlug('  Aratós Tecnología  '));
    assert_eq('mi-podcast-2', normalizePodcastSlug('Mi Podcast 2'));
});

test('validatePodcastSlug rechaza rutas reservadas e inválidas', function () {
    assert_not_null(validatePodcastSlug('admin'));
    assert_not_null(validatePodcastSlug('2026'));
    assert_not_null(validatePodcastSlug('Podcast_Malo'));
    assert_null(validatePodcastSlug('podcast-valido'));
});

test('podcastPath añade el directorio únicamente en modo multipodcast', function () {
    $podcast = ['slug' => 'redes'];
    assert_eq('/redes/feed.xml', podcastPath($podcast, 'feed.xml', true));
    assert_eq('/feed.xml', podcastPath($podcast, 'feed.xml', false));
});
