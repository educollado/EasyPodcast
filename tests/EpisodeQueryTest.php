<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/episode_query.php';

// =============================================================================
// extractEpisodeRouteFromLink
// =============================================================================

test('extractEpisodeRouteFromLink: extrae year/month/slug de ruta relativa', function () {
    $route = extractEpisodeRouteFromLink('/2026/03/mi-episodio');
    assert_eq(['year' => '2026', 'month' => '03', 'slug' => 'mi-episodio'], $route);
});

test('extractEpisodeRouteFromLink: extrae year/month/slug de URL absoluta', function () {
    $route = extractEpisodeRouteFromLink('https://example.com/2026/11/otro-episodio/');
    assert_eq(['year' => '2026', 'month' => '11', 'slug' => 'otro-episodio'], $route);
});

test('extractEpisodeRouteFromLink: enlace sin patrón válido devuelve null', function () {
    assert_null(extractEpisodeRouteFromLink('/episodios/mi-episodio'));
});

// =============================================================================
// episodeMatchesRoute
// =============================================================================

test('episodeMatchesRoute: usa el link como fuente de verdad aunque pub_date sea null', function () {
    $row = [
        'title' => 'Título no relevante',
        'link' => '/2026/03/mi-borrador',
        'pub_date' => null,
    ];
    assert_true(episodeMatchesRoute($row, '2026', '03', 'mi-borrador'));
});

test('episodeMatchesRoute: si link válido no coincide, devuelve false', function () {
    $row = [
        'title' => 'Mi borrador',
        'link' => '/2025/12/mi-borrador',
        'pub_date' => '2026-03-10 10:00:00',
    ];
    assert_true(!episodeMatchesRoute($row, '2026', '03', 'mi-borrador'));
});

test('episodeMatchesRoute: fallback por pub_date+título cuando no hay link válido', function () {
    $row = [
        'title' => 'Mi Episodio',
        'link' => '',
        'pub_date' => '2026-03-10 10:00:00',
    ];
    assert_true(episodeMatchesRoute($row, '2026', '03', 'mi-episodio'));
});

test('episodeMatchesRoute: sin link válido y sin pub_date parseable devuelve false', function () {
    $row = [
        'title' => 'Mi Episodio',
        'link' => '',
        'pub_date' => 'fecha-invalida',
    ];
    assert_true(!episodeMatchesRoute($row, '2026', '03', 'mi-episodio'));
});

