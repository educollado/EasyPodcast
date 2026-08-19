<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/view_helpers.php';

function renderHeaderHeroFixture(string $heroUrl): string
{
    // getPublishedPagesForNav() falla de forma silenciosa con esta ruta inexistente,
    // evitando que el test dependa del driver PDO SQLite.
    $dbPath = sys_get_temp_dir() . '/easypodcast-header-hero-missing/db.sqlite';
    $podcast = ['hero_image_url' => $heroUrl];
    $podcastTitle = 'Podcast de prueba';
    $podcastAuthor = 'Autora';
    $podcastDescription = 'Descripción';
    $podcastImage = '';
    $searchQuery = '';
    unset($podcastHeroImage);

    ob_start();
    include __DIR__ . '/../header.php';
    return (string) ob_get_clean();
}

test('header: muestra el hero opcional y escapa su URL', function () {
    $html = renderHeaderHeroFixture('https://example.com/hero.jpg?x=1&y="2"');

    assert_contains('podcast-site-header has-hero', $html);
    assert_contains('class="podcast-hero-image"', $html);
    assert_contains('x=1&amp;y=&quot;2&quot;', $html);
});

test('header: conserva la cabecera sin hero cuando no hay imagen', function () {
    $html = renderHeaderHeroFixture('');

    assert_true(!str_contains($html, 'has-hero'));
    assert_true(!str_contains($html, 'podcast-hero-image'));
});
