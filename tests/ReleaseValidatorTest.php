<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/release_validator.php';

test('releaseValidationErrors acepta una release coherente', function () {
    $changelog = "# Changelog\n\n## 2.0.0\n\n- Cambio publicado.\n\n## 1.9.11\n";

    assert_eq([], releaseValidationErrors('2.0.0', '2.0.0', $changelog));
});

test('releaseValidationErrors rechaza Siguiente release al crear una versión', function () {
    $changelog = "# Changelog\n\n## Siguiente release\n\n- Cambio pendiente.\n\n## 1.9.11\n";
    $errors = releaseValidationErrors('2.0.0', '2.0.0', $changelog);

    assert_true(count($errors) >= 2);
    assert_contains('todavía contiene', implode("\n", $errors));
    assert_contains('primera sección', implode("\n", $errors));
});

test('releaseValidationErrors rechaza versiones incoherentes o no SemVer', function () {
    $changelog = "# Changelog\n\n## 2.0.0\n";

    assert_contains('APP_VERSION', implode("\n", releaseValidationErrors('2.0.0', '1.9.11', $changelog)));
    assert_contains('SemVer', implode("\n", releaseValidationErrors('v2.0', '2.0.0', $changelog)));
});
