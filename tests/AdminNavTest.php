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

    assert_contains('href="multipodcast.php">Multipodcast', $html);
    assert_contains('class="admin-nav-podcast-selector"', $html);
    assert_contains('name="podcast"', $html);
    assert_contains('Ver podcasts ↗', $html);
    assert_true(!str_contains($html, '>Panel<'));
    assert_true(!str_contains($html, '>Capítulos<'));
});

test('barra de un podcast muestra sus opciones de administración', function () {
    $html = renderAdminNavFixture('podcast', ['id' => 2, 'slug' => 'demo']);

    assert_contains('href="multipodcast.php">Multipodcast', $html);
    assert_contains('>Panel<', $html);
    assert_contains('>Capítulos<', $html);
    assert_contains('href="/demo/"', $html);
    assert_contains('Ver podcast ↗', $html);

    unset($GLOBALS['_multipodcast_enabled'], $GLOBALS['_active_podcast']);
});
