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
