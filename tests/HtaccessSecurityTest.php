<?php

declare(strict_types=1);

test('htaccess bloquea el directorio de tests completo', function () {
    $contents = file_get_contents(__DIR__ . '/../.htaccess');

    assert_true($contents !== false);
    assert_matches('/RewriteRule \^tests\(\?:\/\|\$\) - \[F,L,NC\]/', $contents);
});
