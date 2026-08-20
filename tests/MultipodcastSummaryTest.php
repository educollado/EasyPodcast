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

test('el aviso de activación solo aparece después de cambiar el check', function () {
    $pageSource = file_get_contents(__DIR__ . '/../multipodcast_management.php');
    $scriptSource = file_get_contents(__DIR__ . '/../assets/js/multipodcast.js');

    assert_true(is_string($pageSource));
    assert_true(is_string($scriptSource));
    assert_contains('data-multipodcast-warning role="status" aria-live="polite" hidden', $pageSource);
    assert_contains('warning.hidden = false;', $scriptSource);
    assert_true(!str_contains($scriptSource, "enabledCheckbox.addEventListener('change', updateWarning);\n    updateWarning();"));
});

test('multipodcast.php presenta las herramientas globales como tarjetas', function () {
    $source = file_get_contents(__DIR__ . '/../multipodcast.php');

    assert_true(is_string($source));
    assert_contains('href="multipodcast_management.php"', $source);
    assert_contains('href="cache_management.php"', $source);
    assert_contains('href="update.php"', $source);
    assert_contains('href="change_password.php"', $source);
    assert_contains('href="twofa_management.php"', $source);
    assert_contains('href="backups.php"', $source);
    assert_contains('href="api_tokens.php"', $source);
});
