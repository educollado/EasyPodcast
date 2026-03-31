<?php

declare(strict_types=1);

// =============================================================================
// MigrationConsistencyTest — coherencia entre schema.sql y migration_runner.php
// =============================================================================

$schemaPath  = __DIR__ . '/../schema.sql';
$runnerPath  = __DIR__ . '/../lib/migration_runner.php';
$schemaText  = file_get_contents($schemaPath);
$runnerText  = file_get_contents($runnerPath);

// --- PRAGMA user_version en schema.sql ---------------------------------------

test('schema.sql contiene exactamente un PRAGMA user_version', function () use ($schemaText) {
    $count = preg_match_all('/PRAGMA\s+user_version\s*=\s*\d+/i', $schemaText);
    assert_eq(1, $count, "Se esperaba exactamente un PRAGMA user_version en schema.sql");
});

test('schema.sql PRAGMA user_version coincide con la versión máxima del runner', function () use ($schemaText, $runnerText) {
    preg_match('/PRAGMA\s+user_version\s*=\s*(\d+)/i', $schemaText, $schemaMatch);
    $schemaVersion = (int) ($schemaMatch[1] ?? -1);
    assert_true($schemaVersion > 0, "No se encontró PRAGMA user_version válido en schema.sql");

    // Máximo N de los bloques "if ($version < N)" en runMigrations()
    preg_match_all('/if\s*\(\s*\$version\s*<\s*(\d+)\s*\)/', $runnerText, $blockMatches);
    $maxBlock = $blockMatches[1] ? max(array_map('intval', $blockMatches[1])) : 0;
    assert_true($maxBlock > 0, "No se encontraron bloques if (\$version < N) en migration_runner.php");

    assert_eq(
        $maxBlock,
        $schemaVersion,
        "PRAGMA user_version=$schemaVersion en schema.sql no coincide con versión máxima del runner=$maxBlock"
    );
});

// --- Funciones migration_vN para cada bloque ---------------------------------

test('cada bloque if ($version < N) tiene su función migration_vN definida', function () use ($runnerText) {
    preg_match_all('/if\s*\(\s*\$version\s*<\s*(\d+)\s*\)/', $runnerText, $blockMatches);
    $versions = array_map('intval', $blockMatches[1] ?? []);

    foreach ($versions as $n) {
        $hasFn = (bool) preg_match('/function\s+migration_v' . $n . '\s*\(/', $runnerText);
        assert_true($hasFn, "Falta función migration_v{$n}() en migration_runner.php");
    }
});

// --- Sin versiones duplicadas en los bloques ---------------------------------

test('no hay bloques if ($version < N) duplicados en el runner', function () use ($runnerText) {
    preg_match_all('/if\s*\(\s*\$version\s*<\s*(\d+)\s*\)/', $runnerText, $blockMatches);
    $versions  = array_map('intval', $blockMatches[1] ?? []);
    $unique    = array_unique($versions);
    assert_eq(
        count($unique),
        count($versions),
        "Versiones duplicadas en los bloques del runner: " . implode(', ', $versions)
    );
});
