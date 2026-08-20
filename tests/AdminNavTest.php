<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/view_helpers.php';
require_once __DIR__ . '/../lib/csrf.php';

function renderAdminNavFixture(string $page, ?array $podcast = null): string
{
    $currentAdminPage = $page;
    $dbPath = sys_get_temp_dir() . '/easypodcast-nav-missing/db.sqlite';
    $GLOBALS['_multipodcast_enabled'] = true;
    $GLOBALS['_active_podcast'] = $podcast;
    $_SESSION['csrf_token'] = 'test-token';

    ob_start();
    include __DIR__ . '/../admin_nav.php';
    return (string) ob_get_clean();
}

test('barra multipodcast general oculta las opciones internas y permite elegir podcast', function () {
    $html = renderAdminNavFixture('multipodcast');

    assert_contains('href="multipodcast.php">EasyPodcast', $html);
    assert_contains('class="admin-nav-podcast-selector"', $html);
    assert_contains('name="podcast"', $html);
    assert_contains('data-navigation-url="multipodcast.php" selected>Multipodcast</option>', $html);
    assert_contains('href="cache_management.php"', $html);
    assert_contains('href="update.php"', $html);
    assert_contains('href="change_password.php"', $html);
    assert_contains('href="twofa_management.php"', $html);
    assert_contains('href="backups.php"', $html);
    assert_contains('href="api_tokens.php"', $html);
    assert_contains('Ver podcasts ↗', $html);
    assert_true(!str_contains($html, '>Panel<'));
    assert_true(!str_contains($html, '>Capítulos<'));
});

test('el selector superior navega a Multipodcast sin enviarlo como podcast', function () {
    $script = file_get_contents(__DIR__ . '/../assets/js/admin.js');

    assert_true(is_string($script));
    assert_contains('selectedOption.dataset.navigationUrl', $script);
    assert_contains('window.location.assign(navigationUrl);', $script);
});

test('barra de un podcast muestra sus opciones de administración', function () {
    $html = renderAdminNavFixture('podcast', ['id' => 2, 'slug' => 'demo']);

    assert_contains('href="multipodcast.php">EasyPodcast', $html);
    assert_contains('>Panel<', $html);
    assert_contains('>Capítulos<', $html);
    assert_contains('href="/demo/"', $html);
    assert_contains('Ver podcast ↗', $html);

    assert_true(!str_contains($html, 'href="cache_management.php"'));
    assert_true(!str_contains($html, 'href="backups.php"'));

    unset($GLOBALS['_multipodcast_enabled'], $GLOBALS['_active_podcast']);
});

test('caché permanece en el menú Multipodcast y cambia de podcast sin salir', function () {
    $html = renderAdminNavFixture('cache', ['id' => 2, 'slug' => 'demo']);

    assert_contains('action="cache_management.php"', $html);
    assert_contains('href="multipodcast.php"', $html);
    assert_contains('class="admin-nav-link active" href="cache_management.php"', $html);
    assert_true(!str_contains($html, '>Panel<'));
    assert_true(!str_contains($html, '>Capítulos<'));

    unset($GLOBALS['_multipodcast_enabled'], $GLOBALS['_active_podcast']);
});

test('el panel de podcast no duplica las herramientas globales de Multipodcast', function () {
    $source = file_get_contents(__DIR__ . '/../admin.php');

    assert_true(is_string($source));
    assert_contains('<?php if (!$adminMultipodcastEnabled): ?>', $source);
    assert_contains('<a class="admin-card" href="backups.php">', $source);
    assert_contains('<a class="admin-card" href="api_tokens.php">', $source);
});
