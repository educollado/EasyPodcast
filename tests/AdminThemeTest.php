<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin_theme.php';
require_once __DIR__ . '/../lib/podcast_context.php';

function adminThemeTestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

test('publicThemeMode: por defecto usa normal', function () {
    unset($GLOBALS['_public_theme_mode']);
    assert_eq('normal', publicThemeMode());
});

test('adminTheme: por defecto usa el tema EasyPodcast', function () {
    unset($GLOBALS['_admin_theme']);
    assert_eq('easypodcast', adminTheme());
});

test('ADMIN_THEMES: incluye el tema Corporate', function () {
    assert_eq('Corporate', ADMIN_THEMES['corporate'] ?? null);
});

test('publicThemeMode: devuelve auto cuando está cargado en globals', function () {
    $GLOBALS['_public_theme_mode'] = 'auto';
    assert_eq('auto', publicThemeMode());
});

test('loadAdminTheme: carga tema visual y modo público desde la BD', function () {
    if (!adminThemeTestHasSqliteDriver()) {
        return;
    }

    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-admin-theme-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de AdminThemeTest');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE podcast (
                id INTEGER PRIMARY KEY,
                admin_theme TEXT NOT NULL DEFAULT 'default',
                public_theme_mode_auto INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec("INSERT INTO podcast (id, admin_theme, public_theme_mode_auto) VALUES (1, 'corporate', 1)");

        loadAdminTheme($dbPath);

        assert_eq('corporate', adminTheme());
        assert_eq('auto', publicThemeMode());
    } finally {
        unset($GLOBALS['_admin_theme'], $GLOBALS['_public_theme_mode']);
        @unlink($dbPath);
    }
});

test('loadAdminTheme: usa el tema propio de la portada resumen multipodcast', function () {
    if (!adminThemeTestHasSqliteDriver()) {
        return;
    }

    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-summary-theme-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal del tema del resumen');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE podcast (id INTEGER PRIMARY KEY, admin_theme TEXT, public_theme_mode_auto INTEGER)");
        $pdo->exec("INSERT INTO podcast VALUES (1, 'corporate', 1)");
        $pdo->exec("CREATE TABLE app_settings (id INTEGER PRIMARY KEY, multipodcast_enabled INTEGER, homepage_podcast_id INTEGER, summary_hero_image_url TEXT, summary_title TEXT, summary_subtitle TEXT, summary_theme TEXT, summary_language TEXT, primary_podcast_id INTEGER)");
        $pdo->exec("INSERT INTO app_settings VALUES (1, 1, NULL, NULL, NULL, NULL, 'monokai', 'es_ES', 1)");
        $GLOBALS['_active_podcast'] = null;

        loadAdminTheme($dbPath);

        assert_eq('monokai', adminTheme());
        assert_eq('normal', publicThemeMode());
    } finally {
        unset($GLOBALS['_active_podcast'], $GLOBALS['_admin_theme'], $GLOBALS['_public_theme_mode']);
        @unlink($dbPath);
    }
});

test('la gestión Multipodcast aplica y previsualiza el tema propio del resumen', function () {
    $pageSource = file_get_contents(__DIR__ . '/../multipodcast_management.php');
    $scriptSource = file_get_contents(__DIR__ . '/../assets/js/multipodcast.js');

    assert_true(is_string($pageSource));
    assert_true(is_string($scriptSource));
    assert_contains("data-theme=\"<?= esc(\$multipodcastTheme) ?>\"", $pageSource);
    assert_contains('data-summary-theme-selector', $pageSource);
    assert_contains('document.documentElement.dataset.theme = themeSelect.value', $scriptSource);
});

test('la gestión Multipodcast permite elegir un idioma independiente', function () {
    $source = file_get_contents(__DIR__ . '/../multipodcast_management.php');
    assert_true(is_string($source));
    assert_contains('name="summary_language"', $source);
    assert_contains("\$settings['summary_language']", $source);
});
