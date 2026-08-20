<?php

declare(strict_types=1);

test('la portada resumen filtra los podcasts ocultos', function () {
    $source = file_get_contents(__DIR__ . '/../multipodcast_home.php');

    assert_true(is_string($source));
    assert_contains('p.include_in_summary = 1', $source);
});

test('la gestión del podcast ofrece el selector de visibilidad dentro del formulario', function () {
    $source = file_get_contents(__DIR__ . '/../podcast_management.php');

    assert_true(is_string($source));
    assert_contains('$showSummaryVisibilityOption', $source);
    assert_contains('name="include_in_summary"', $source);
    assert_contains('form="podcast-metadata-form"', $source);
});
