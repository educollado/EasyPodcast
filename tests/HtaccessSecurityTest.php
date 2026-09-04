<?php

declare(strict_types=1);

test('htaccess bloquea el directorio de tests completo', function () {
    $contents = file_get_contents(__DIR__ . '/../.htaccess');

    assert_true($contents !== false);
    assert_matches('/RewriteRule \^tests\(\?:\/\|\$\) - \[F,L,NC\]/', $contents);
});

test('htaccess resuelve episodios legacy antes que las rutas genéricas', function () {
    $contents = file_get_contents(__DIR__ . '/../.htaccess');
    assert_true(is_string($contents));

    $legacyEpisode = strpos($contents, 'episode.php?year=$1&month=$2&slug=$3');
    $genericRoute = strpos($contents, 'route.php?first=$1&second=$2&third=$3');
    assert_true($legacyEpisode !== false);
    assert_true($genericRoute !== false);
    assert_true($legacyEpisode < $genericRoute);
});

test('htaccess bloquea ejecutables también en medios de podcasts', function () {
    $contents = file_get_contents(__DIR__ . '/../.htaccess');
    assert_true(is_string($contents));
    assert_true(str_contains($contents, '^(?:[a-z0-9-]+/)?(?:audios|images)'));
});

test('htaccess redirige el antiguo gestor al nuevo nombre multipodcast', function () {
    $contents = file_get_contents(__DIR__ . '/../.htaccess');
    assert_true(is_string($contents));
    assert_true(str_contains($contents, 'RewriteRule ^podcasts\\.php$ /multipodcast.php [R=301,L]'));
});

test('la plantilla predeterminada contiene las reglas esenciales y no es accesible públicamente', function () {
    $contents = file_get_contents(__DIR__ . '/../.htaccess');
    $template = file_get_contents(__DIR__ . '/../.htaccess.default');

    assert_true(is_string($contents) && is_string($template));
    assert_contains('|\\.htaccess\\.default)', $contents);
    assert_contains('RewriteEngine on', $template);
    assert_contains('podcast\.sqlite', $template);
    assert_contains('Options -Indexes', $template);
    assert_true(!str_contains($template, '# BEGIN EasyPodcast: bloqueo por IP de admin.php'));
});

test('la gestión de caché permite regenerar htaccess con protección CSRF', function () {
    $page = file_get_contents(__DIR__ . '/../cache_management.php');
    $handler = file_get_contents(__DIR__ . '/../lib/cache_management_handler.php');

    assert_true(is_string($page) && is_string($handler));
    assert_contains('value="regenerate_htaccess"', $page);
    assert_contains("__('Regenerar .htaccess')", $page);
    assert_contains('data-confirm-message=', $page);
    assert_contains('csrf_token()', $page);
    assert_contains("\$action === 'regenerate_htaccess'", $handler);
    assert_contains('restoreDefaultHtaccess(', $handler);
    $imagesPosition = strpos($page, "__('Imágenes generadas')");
    $htaccessPosition = strpos($page, "__('.htaccess generado')");
    assert_true(is_int($imagesPosition) && is_int($htaccessPosition) && $imagesPosition < $htaccessPosition);
});

test('el manual explica cómo retirar por completo el bloqueo de admin por IP', function () {
    $manual = file_get_contents(__DIR__ . '/../MANUAL_USUARIO.md');

    assert_true(is_string($manual));
    assert_contains('Recuperar el acceso si te has bloqueado por error', $manual);
    assert_contains('# BEGIN EasyPodcast: bloqueo por IP de admin.php', $manual);
    assert_contains('# END EasyPodcast: bloqueo por IP de admin.php', $manual);
    assert_contains('elimina **únicamente el bloque completo**', $manual);
});
