<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/stats_downloads_handler.php';

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
