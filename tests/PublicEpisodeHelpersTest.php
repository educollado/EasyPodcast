<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/public_episode_helpers.php';

// =============================================================================
// buildEpisodePath
// =============================================================================

test('buildEpisodePath: construye ruta /YYYY/MM/slug', function () {
    assert_eq('/2024/03/mi-episodio', buildEpisodePath('2024-03-15 10:30:00', 'Mi Episodio'));
});

test('buildEpisodePath: mes con cero a la izquierda', function () {
    assert_eq('/2024/01/primer-episodio', buildEpisodePath('2024-01-05 00:00:00', 'Primer Episodio'));
});

test('buildEpisodePath: título con caracteres especiales genera slug válido', function () {
    $result = buildEpisodePath('2024-06-01 00:00:00', '¡Hola! Capítulo #5');
    assert_matches('#^/2024/06/[a-z0-9-]+$#', $result);
});

// =============================================================================
// resolveEpisodeHref
// =============================================================================

test('resolveEpisodeHref: enlace guardado se usa directamente', function () {
    assert_eq(
        '/2023/11/episodio-guardado',
        resolveEpisodeHref('/2023/11/episodio-guardado', '2024-03-15', 'Otro Título')
    );
});

test('resolveEpisodeHref: enlace vacío genera ruta desde fecha y título', function () {
    assert_eq(
        '/2024/03/mi-episodio',
        resolveEpisodeHref('', '2024-03-15 10:00:00', 'Mi Episodio')
    );
});

test('resolveEpisodeHref: enlace null genera ruta desde fecha y título', function () {
    assert_eq(
        '/2024/03/mi-episodio',
        resolveEpisodeHref(null, '2024-03-15 10:00:00', 'Mi Episodio')
    );
});

test('resolveEpisodeHref: enlace con sólo espacios se trata como vacío', function () {
    $result = resolveEpisodeHref('   ', '2024-03-15 10:00:00', 'Mi Episodio');
    assert_eq('/2024/03/mi-episodio', $result);
});

test('resolvePodcastEpisodeHref: antepone el directorio en multipodcast', function () {
    $result = resolvePodcastEpisodeHref(
        ['slug' => 'mi-podcast'],
        '/2024/03/mi-episodio',
        '2024-03-15 10:00:00',
        'Mi Episodio',
        true
    );
    assert_eq('/mi-podcast/2024/03/mi-episodio', $result);
});

test('resolvePodcastEpisodeHref: reemplaza un directorio antiguo sin duplicarlo', function () {
    $result = resolvePodcastEpisodeHref(
        ['slug' => 'nuevo'],
        'https://example.test/antiguo/2026/08/episodio',
        '2026-08-20 10:00:00',
        'Episodio',
        true
    );
    assert_eq('/nuevo/2026/08/episodio', $result);
});

// =============================================================================
// slugFromEpisodeLink
// =============================================================================

test('slugFromEpisodeLink: extrae slug de ruta /YYYY/MM/slug', function () {
    assert_eq('mi-episodio', slugFromEpisodeLink('/2024/03/mi-episodio'));
});

test('slugFromEpisodeLink: acepta ruta con barra final', function () {
    assert_eq('mi-episodio', slugFromEpisodeLink('/2024/03/mi-episodio/'));
});

test('slugFromEpisodeLink: URL absoluta → extrae sólo el slug', function () {
    assert_eq('mi-episodio', slugFromEpisodeLink('https://example.com/2024/03/mi-episodio'));
});

test('slugFromEpisodeLink: cadena vacía → null', function () {
    assert_null(slugFromEpisodeLink(''));
});

test('slugFromEpisodeLink: null → null', function () {
    assert_null(slugFromEpisodeLink(null));
});

test('slugFromEpisodeLink: ruta sin slug válido → null', function () {
    assert_null(slugFromEpisodeLink('/2024/03/'));
});

test('slugFromEpisodeLink: ruta sin formato YYYY/MM → null', function () {
    assert_null(slugFromEpisodeLink('/episodios/mi-episodio'));
});
