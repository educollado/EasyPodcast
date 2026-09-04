<?php

declare(strict_types=1);

test('las herramientas globales exigen administrador global', function () {
    foreach (['multipodcast.php', 'multipodcast_management.php', 'podcasts_management.php', 'users_management.php', 'admin_account.php', 'security.php', 'media_cleanup.php', 'cache_management.php', 'backups.php', 'update.php'] as $file) {
        $source = file_get_contents(__DIR__ . '/../' . $file);
        assert_true(is_string($source));
        assert_contains('requireGlobalAdminAccess();', $source, $file . ' no restringe el acceso global');
    }
});

test('los tokens quedan ligados al usuario y las acciones globales exigen alcance administrativo', function () {
    $tokens = file_get_contents(__DIR__ . '/../lib/api_tokens_handler.php');
    $router = file_get_contents(__DIR__ . '/../api/index.php');
    assert_true(is_string($tokens) && is_string($router));
    assert_contains("':user_id' => adminSessionUserId()", $tokens);
    assert_contains("apiRequireScope(\$apiToken, 'admin'); apiClearCache();", $router);
    assert_contains("apiRequireScope(\$apiToken, 'admin'); apiRegenerateImages(\$pdo);", $router);
});
