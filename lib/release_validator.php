<?php

declare(strict_types=1);

/**
 * Comprueba que versión, APP_VERSION y primera sección del changelog coinciden.
 *
 * @return string[]
 */
function releaseValidationErrors(string $version, string $appVersion, string $changelog): array
{
    $errors = [];

    if (preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/', $version) !== 1) {
        $errors[] = sprintf('La versión "%s" no usa el formato SemVer X.Y.Z.', $version);
        return $errors;
    }

    if ($appVersion !== $version) {
        $errors[] = sprintf('APP_VERSION es %s, pero la release solicitada es %s.', $appVersion, $version);
    }

    if (preg_match('/^## Siguiente release$/m', $changelog) === 1) {
        $errors[] = 'CHANGELOG.md todavía contiene el encabezado "## Siguiente release".';
    }

    $expectedHeader = '## ' . $version;
    if (preg_match('/^' . preg_quote($expectedHeader, '/') . '$/m', $changelog) !== 1) {
        $errors[] = sprintf('CHANGELOG.md no contiene el encabezado exacto "%s".', $expectedHeader);
    }

    if (preg_match('/\A# Changelog\R\R' . preg_quote($expectedHeader, '/') . '\R/', $changelog) !== 1) {
        $errors[] = sprintf('La primera sección de CHANGELOG.md debe ser "%s".', $expectedHeader);
    }

    return $errors;
}
