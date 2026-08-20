<?php

declare(strict_types=1);

require_once __DIR__ . '/../canonical_redirect.php';

test('el idioma Multipodcast se limita a la portada resumen y al panel global', function () {
    $settings = ['multipodcast_enabled' => 1, 'summary_language' => 'gl_ES'];
    $oldSession = $_SESSION ?? [];
    $oldScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    try {
        $_SESSION = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        assert_true(requestUsesMultipodcastLocale($settings, null));
        assert_true(!requestUsesMultipodcastLocale($settings, ['id' => 2, 'app_language' => 'en_US']));

        $_SESSION = ['admin_user' => 'admin', 'admin_is_global' => 1];
        $_SERVER['SCRIPT_NAME'] = '/multipodcast_management.php';
        assert_true(requestUsesMultipodcastLocale(['multipodcast_enabled' => 0], ['id' => 1]));

        $_SERVER['SCRIPT_NAME'] = '/cache_management.php';
        assert_true(requestUsesMultipodcastLocale($settings, ['id' => 2]));

        $_SERVER['SCRIPT_NAME'] = '/podcast_management.php';
        assert_true(!requestUsesMultipodcastLocale($settings, ['id' => 2]));

        $_SESSION = ['admin_user' => 'user@example.com', 'admin_is_global' => 0];
        $_SERVER['SCRIPT_NAME'] = '/api_tokens.php';
        assert_true(!requestUsesMultipodcastLocale($settings, ['id' => 2]));
    } finally {
        $_SESSION = $oldSession;
        if ($oldScriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $oldScriptName;
        }
    }
});

test('cambiar el idioma de un podcast actualiza únicamente su fila', function () {
    $source = file_get_contents(__DIR__ . '/../admin.php');
    assert_true(is_string($source));
    assert_contains('UPDATE podcast SET app_language = :lang WHERE id = :podcast_id', $source);
    assert_contains("':podcast_id' => \$podcastId", $source);
});
