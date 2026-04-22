<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin_theme.php';

test('normalizePublicThemeMode: acepta auto', function () {
    assert_eq('auto', normalizePublicThemeMode('auto'));
});

test('normalizePublicThemeMode: cualquier valor inválido vuelve a normal', function () {
    assert_eq('normal', normalizePublicThemeMode('otro'));
    assert_eq('normal', normalizePublicThemeMode(null));
});

test('buildPublicThemeModeUrl: añade theme_mode a una ruta simple', function () {
    assert_eq('/?theme_mode=auto', buildPublicThemeModeUrl('auto', '/'));
});

test('buildPublicThemeModeUrl: preserva query existente', function () {
    assert_eq(
        '/search.php?q=podcast&page=2&theme_mode=auto',
        buildPublicThemeModeUrl('auto', '/search.php?q=podcast&page=2')
    );
});

test('buildPublicThemeModeUrl: reemplaza theme_mode anterior', function () {
    assert_eq(
        '/search.php?q=podcast&page=2&theme_mode=normal',
        buildPublicThemeModeUrl('normal', '/search.php?q=podcast&page=2&theme_mode=auto')
    );
});
