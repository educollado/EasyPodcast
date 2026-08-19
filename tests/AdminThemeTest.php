<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin_theme.php';

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
