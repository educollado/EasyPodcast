<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/stats_downloads_handler.php';
require_once __DIR__ . '/../lib/i18n.php';

i18n_load('es_ES');

// =============================================================================
// formatStatsDate
// =============================================================================

test('formatStatsDate: formatea fecha válida en dd/mm/YYYY H:i:s', function () {
    $result = formatStatsDate('2024-03-15 10:30:45');
    assert_eq('15/03/2024 10:30:45', $result);
});

test('formatStatsDate: devuelve misma cadena para fecha inválida', function () {
    $result = formatStatsDate('no-es-una-fecha');
    assert_eq('no-es-una-fecha', $result);
});

test('formatStatsDate: maneja fecha con formato diferente', function () {
    $result = formatStatsDate('2024-03-15T10:30:45');
    assert_eq('15/03/2024 10:30:45', $result);
});

test('formatStatsDate: maneja timestamp de época', function () {
    $result = formatStatsDate('1970-01-01 00:00:00');
    assert_eq('01/01/1970 00:00:00', $result);
});

// =============================================================================
// formatMonthYear
// =============================================================================

test('formatMonthYear: formatea enero correctamente', function () {
    $result = formatMonthYear(2024, 1);
    assert_eq('Ene 2024', $result);
});

test('formatMonthYear: formatea todas las meses correctamente', function () {
    $months = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
    ];
    
    foreach ($months as $month => $expectedName) {
        $result = formatMonthYear(2024, $month);
        assert_eq($expectedName . ' 2024', $result);
    }
});

test('formatMonthYear: maneja mes inválido', function () {
    $result = formatMonthYear(2024, 13);
    assert_eq('Mes 13 2024', $result);
});

test('formatMonthYear: maneja mes 0', function () {
    $result = formatMonthYear(2024, 0);
    assert_eq('Mes 0 2024', $result);
});

// =============================================================================
// getActionTypeLabel
// =============================================================================

test('getActionTypeLabel: play se muestra como Reproducción', function () {
    $result = getActionTypeLabel('play');
    assert_eq('Reproducción', $result);
});

test('getActionTypeLabel: feed se muestra como Feed', function () {
    $result = getActionTypeLabel('feed');
    assert_eq('Feed', $result);
});

test('getActionTypeLabel: download se muestra como Descarga', function () {
    $result = getActionTypeLabel('download');
    assert_eq('Descarga', $result);
});

// =============================================================================
// getStatsPageNumber
// =============================================================================

test('getStatsPageNumber: devuelve el entero válido indicado', function () {
    $result = getStatsPageNumber('diario_page', ['diario_page' => '3']);
    assert_eq(3, $result);
});

test('getStatsPageNumber: corrige valores menores que 1', function () {
    $result = getStatsPageNumber('diario_page', ['diario_page' => '0']);
    assert_eq(1, $result);
});

test('getStatsPageNumber: ignora valores no numéricos', function () {
    $result = getStatsPageNumber('diario_page', ['diario_page' => 'abc']);
    assert_eq(1, $result);
});

// =============================================================================
// paginateStatsRows
// =============================================================================

test('paginateStatsRows: pagina correctamente la primera página', function () {
    $rows = [];
    foreach (range(1, 250) as $n) {
        $rows[] = ['id' => $n];
    }

    $page = paginateStatsRows($rows, 1, 100);

    assert_eq(100, count($page['rows']));
    assert_eq(1, $page['page']);
    assert_eq(3, $page['total_pages']);
    assert_eq(250, $page['total_rows']);
    assert_eq(1, $page['from']);
    assert_eq(100, $page['to']);
    assert_eq(1, $page['rows'][0]['id']);
    assert_eq(100, $page['rows'][99]['id']);
});

test('paginateStatsRows: pagina correctamente una página intermedia', function () {
    $rows = [];
    foreach (range(1, 250) as $n) {
        $rows[] = ['id' => $n];
    }

    $page = paginateStatsRows($rows, 2, 100);

    assert_eq(100, count($page['rows']));
    assert_eq(2, $page['page']);
    assert_eq(101, $page['from']);
    assert_eq(200, $page['to']);
    assert_eq(101, $page['rows'][0]['id']);
    assert_eq(200, $page['rows'][99]['id']);
});

test('paginateStatsRows: ajusta páginas fuera de rango al máximo disponible', function () {
    $rows = [];
    foreach (range(1, 250) as $n) {
        $rows[] = ['id' => $n];
    }

    $page = paginateStatsRows($rows, 9, 100);

    assert_eq(3, $page['page']);
    assert_eq(201, $page['from']);
    assert_eq(250, $page['to']);
    assert_eq(50, count($page['rows']));
});

test('paginateStatsRows: devuelve metadatos vacíos si no hay filas', function () {
    $page = paginateStatsRows([], 1, 100);

    assert_eq([], $page['rows']);
    assert_eq(1, $page['page']);
    assert_eq(0, $page['total_pages']);
    assert_eq(0, $page['total_rows']);
    assert_eq(0, $page['from']);
    assert_eq(0, $page['to']);
});
