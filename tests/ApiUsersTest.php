<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/api_users_handler.php';

test('la API de usuarios exige alcance global y enruta todas las operaciones', function () {
    $router = file_get_contents(__DIR__ . '/../api/index.php');
    assert_true(is_string($router));
    assert_contains("case 'users':", $router);
    assert_contains("apiRequireScope(\$apiToken, 'admin');", $router);
    assert_contains('apiListUserAssignablePodcasts($pdo)', $router);
    assert_contains('apiListUsers($pdo)', $router);
    assert_contains('apiGetUser($pdo, $id)', $router);
    assert_contains('apiCreateUser($pdo, $body)', $router);
    assert_contains('apiUpdateUser($pdo, $id, $body)', $router);
    assert_contains('apiDeleteUser($pdo, $id)', $router);
});

test('la API resuelve asignaciones múltiples por ID y directorio', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE podcast (id INTEGER PRIMARY KEY, title TEXT NOT NULL, slug TEXT)');
    $pdo->exec('CREATE TABLE management (id INTEGER PRIMARY KEY, username TEXT, password TEXT, first_name TEXT, last_name TEXT, email TEXT, is_global INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE management_podcasts (management_id INTEGER, podcast_id INTEGER, PRIMARY KEY (management_id, podcast_id))');
    $pdo->exec("INSERT INTO podcast (id, title, slug) VALUES (1, 'Uno', 'uno'), (2, 'Dos', 'dos')");

    $data = apiValidateUserPayload($pdo, [
        'first_name' => 'Ana',
        'last_name' => 'García',
        'email' => 'ANA@example.com',
        'password' => 'secreto-seguro',
        'podcast_ids' => [2],
        'podcast_slugs' => ['uno'],
        'is_active' => true,
    ], null);

    assert_eq('ana@example.com', $data['email']);
    assert_eq([1, 2], $data['podcast_ids']);
    assert_eq(1, $data['is_active']);
});

test('las respuestas de usuarios no exponen credenciales', function () {
    $source = file_get_contents(__DIR__ . '/../lib/api_users_handler.php');
    assert_true(is_string($source));
    assert_true(!str_contains($source, "'password' => \$user"));
    assert_contains("'podcasts' => \$stmt->fetchAll(PDO::FETCH_ASSOC)", $source);
    assert_contains("DELETE FROM api_tokens WHERE user_id = :id", $source);
});
