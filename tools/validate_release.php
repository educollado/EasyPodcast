<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este validador solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$version = trim((string) ($argv[1] ?? ''));
if ($version === '') {
    fwrite(STDERR, "Uso: php tools/validate_release.php X.Y.Z\n");
    exit(1);
}

require __DIR__ . '/../lib/version.php';
require __DIR__ . '/../lib/release_validator.php';

$changelogPath = __DIR__ . '/../CHANGELOG.md';
$changelog = file_get_contents($changelogPath);
if ($changelog === false) {
    fwrite(STDERR, "No se pudo leer CHANGELOG.md.\n");
    exit(1);
}

$errors = releaseValidationErrors($version, APP_VERSION, $changelog);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, '- ' . $error . "\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Release %s validada correctamente.\n", $version));
