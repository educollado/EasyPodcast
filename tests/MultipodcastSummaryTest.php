<?php

declare(strict_types=1);

test('la portada resumen filtra los podcasts ocultos', function () {
    $source = file_get_contents(__DIR__ . '/../multipodcast_home.php');

    assert_true(is_string($source));
    assert_contains('p.include_in_summary = 1', $source);
});

test('el pie oculta la referencia a la instalación en la portada resumen', function () {
    $source = file_get_contents(__DIR__ . '/../footer.php');

    assert_true(is_string($source));
    assert_contains('multipodcastEnabled($_footerPdo) && activePodcast($_footerPdo) === null', $source);
    assert_contains('if ($_footerShowHomeLink)', $source);
});

test('la portada resumen se sirve y se guarda en la caché pública', function () {
    $source = file_get_contents(__DIR__ . '/../index.php');

    assert_true(is_string($source));
    $summaryBranch = strpos($source, 'if (multipodcastEnabled($contextPdo) && activePodcast($contextPdo) === null)');
    $cacheRead = strpos($source, "tryServeWebCache(\$dbPath, 'text/html; charset=UTF-8')", $summaryBranch ?: 0);
    $summaryRender = strpos($source, "require __DIR__ . '/multipodcast_home.php'", $summaryBranch ?: 0);
    $cacheWrite = strpos($source, 'storeWebCache($dbPath, $cachedOutput)', $summaryRender ?: 0);

    assert_true(is_int($summaryBranch));
    assert_true(is_int($cacheRead) && is_int($summaryRender) && $cacheRead < $summaryRender);
    assert_true(is_int($cacheWrite) && $summaryRender < $cacheWrite);
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
    assert_contains('href="podcasts_management.php"', $source);
    assert_contains('href="cache_management.php"', $source);
    assert_contains('href="update.php"', $source);
    assert_contains('href="change_password.php"', $source);
    assert_contains('href="twofa_management.php"', $source);
    assert_contains('href="backups.php"', $source);
    assert_contains('href="api_tokens.php"', $source);
});

test('la gestión de podcasts crea primero y lista después fuera de la configuración', function () {
    $podcastsSource = file_get_contents(__DIR__ . '/../podcasts_management.php');
    $settingsSource = file_get_contents(__DIR__ . '/../multipodcast_management.php');

    assert_true(is_string($podcastsSource));
    assert_true(is_string($settingsSource));
    $createPosition = strpos($podcastsSource, "__('Crear un podcast nuevo')");
    $listPosition = strpos($podcastsSource, "__('Podcasts disponibles')");
    assert_true(is_int($createPosition) && is_int($listPosition) && $createPosition < $listPosition);
    assert_true(!str_contains($settingsSource, "__('Crear un podcast nuevo')"));
    assert_true(!str_contains($settingsSource, "__('Podcasts disponibles')"));
});
