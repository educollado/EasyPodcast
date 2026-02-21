#!/usr/bin/env php
<?php

declare(strict_types=1);

// =============================================================================
// Runner de tests mínimo — sin dependencias externas
// Uso: php tests/run.php
// =============================================================================

$_TR = ['passed' => 0, 'failed' => 0, 'errors' => []];

// ---- Funciones de test -------------------------------------------------------

function test(string $name, callable $fn): void
{
    global $_TR;
    try {
        $fn();
        $_TR['passed']++;
        echo '.';
    } catch (AssertionError $e) {
        $_TR['failed']++;
        $_TR['errors'][] = "FAIL [$name]\n     " . $e->getMessage();
        echo 'F';
    } catch (Throwable $e) {
        $_TR['failed']++;
        $_TR['errors'][] = "ERROR [$name]\n     " . get_class($e) . ': ' . $e->getMessage();
        echo 'E';
    }
}

// ---- Helpers de aserción -----------------------------------------------------

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError($msg ?: sprintf(
            "esperado %s, obtenido %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_null(mixed $value, string $msg = ''): void
{
    if ($value !== null) {
        throw new AssertionError($msg ?: sprintf("esperado null, obtenido %s", var_export($value, true)));
    }
}

function assert_not_null(mixed $value, string $msg = ''): void
{
    if ($value === null) {
        throw new AssertionError($msg ?: "esperado valor no nulo");
    }
}

function assert_true(bool $value, string $msg = ''): void
{
    if (!$value) {
        throw new AssertionError($msg ?: "esperado true");
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionError($msg ?: sprintf("'%s' no contiene '%s'", $haystack, $needle));
    }
}

function assert_matches(string $pattern, string $subject, string $msg = ''): void
{
    if (!preg_match($pattern, $subject)) {
        throw new AssertionError($msg ?: sprintf("'%s' no coincide con patrón %s", $subject, $pattern));
    }
}

// ---- Cargar y ejecutar archivos de test --------------------------------------

$testFiles = glob(__DIR__ . '/*Test.php') ?: [];
sort($testFiles);

foreach ($testFiles as $file) {
    echo "\n" . basename($file) . "\n";
    require $file;
}

// ---- Informe final -----------------------------------------------------------

echo "\n";
if ($_TR['errors']) {
    foreach ($_TR['errors'] as $err) {
        echo $err . "\n";
    }
    echo "\n";
}

$total = $_TR['passed'] + $_TR['failed'];
$icon  = $_TR['failed'] === 0 ? 'OK' : 'FALLO';
echo sprintf("[%s] %d tests: %d passed, %d failed\n",
    $icon, $total, $_TR['passed'], $_TR['failed']);

exit($_TR['failed'] > 0 ? 1 : 0);
