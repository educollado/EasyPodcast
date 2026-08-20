<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/i18n.php';

/**
 * GET /api/v1/users/podcasts
 * Devuelve los podcasts que pueden asignarse a usuarios.
 */
function apiListUserAssignablePodcasts(PDO $pdo): void
{
    $podcasts = $pdo->query(
        'SELECT id, title, slug FROM podcast ORDER BY title COLLATE NOCASE, id'
    )->fetchAll(PDO::FETCH_ASSOC);

    apiJsonResponse(['success' => true, 'data' => ['items' => $podcasts]]);
}

/**
 * GET /api/v1/users
 */
function apiListUsers(PDO $pdo): void
{
    $users = $pdo->query(
        'SELECT id, first_name, last_name, email, is_active, created_at, updated_at
           FROM management
          WHERE is_global = 0
          ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE, email COLLATE NOCASE'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $user = apiUserResponseData($pdo, $user);
    }
    unset($user);

    apiJsonResponse(['success' => true, 'data' => ['items' => $users]]);
}

/**
 * GET /api/v1/users/{id}
 */
function apiGetUser(PDO $pdo, int $id): void
{
    $user = apiFindPodcastUser($pdo, $id);
    if ($user === null) {
        apiError(__('Usuario no encontrado.'), 404);
    }

    apiJsonResponse(['success' => true, 'data' => apiUserResponseData($pdo, $user)]);
}

/**
 * POST /api/v1/users
 */
function apiCreateUser(PDO $pdo, array $body): void
{
    $data = apiValidateUserPayload($pdo, $body, null);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO management
                (username, password, first_name, last_name, email, is_global, is_active)
             VALUES
                (:email, :password, :first_name, :last_name, :email, 0, :is_active)'
        );
        $stmt->execute([
            ':email' => $data['email'],
            ':password' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':is_active' => $data['is_active'],
        ]);
        $id = (int) $pdo->lastInsertId();
        apiReplaceUserPodcastAssignments($pdo, $id, $data['podcast_ids']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiError(__('No se pudo crear el usuario.'), 500);
    }

    $created = apiFindPodcastUser($pdo, $id);
    apiJsonResponse(['success' => true, 'data' => apiUserResponseData($pdo, $created ?? [])], 201);
}

/**
 * POST /api/v1/users/{id}
 * Solo modifica los campos enviados. Una contraseña vacía conserva la actual.
 */
function apiUpdateUser(PDO $pdo, int $id, array $body): void
{
    $existing = apiFindPodcastUser($pdo, $id);
    if ($existing === null) {
        apiError(__('Usuario no encontrado.'), 404);
    }

    $data = apiValidateUserPayload($pdo, $body, $existing);
    $sets = [
        'first_name = :first_name',
        'last_name = :last_name',
        'email = :email',
        'username = :email',
        'is_active = :is_active',
        "updated_at = datetime('now')",
    ];
    $params = [
        ':first_name' => $data['first_name'],
        ':last_name' => $data['last_name'],
        ':email' => $data['email'],
        ':is_active' => $data['is_active'],
        ':id' => $id,
    ];
    if ($data['password'] !== '') {
        $sets[] = 'password = :password';
        $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE management SET ' . implode(', ', $sets) . ' WHERE id = :id AND is_global = 0');
        $stmt->execute($params);
        apiReplaceUserPodcastAssignments($pdo, $id, $data['podcast_ids']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiError(__('No se pudo actualizar el usuario.'), 500);
    }

    $updated = apiFindPodcastUser($pdo, $id);
    apiJsonResponse(['success' => true, 'data' => apiUserResponseData($pdo, $updated ?? [])]);
}

/**
 * DELETE /api/v1/users/{id}
 */
function apiDeleteUser(PDO $pdo, int $id): void
{
    if (apiFindPodcastUser($pdo, $id) === null) {
        apiError(__('Usuario no encontrado.'), 404);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM api_tokens WHERE user_id = :id')->execute([':id' => $id]);
        $stmt = $pdo->prepare('DELETE FROM management WHERE id = :id AND is_global = 0');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('User not deleted');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiError(__('No se pudo eliminar el usuario.'), 500);
    }

    apiJsonResponse(['success' => true, 'data' => ['deleted' => true]]);
}

function apiFindPodcastUser(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, is_active, created_at, updated_at
           FROM management WHERE id = :id AND is_global = 0 LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($user) ? $user : null;
}

function apiUserResponseData(PDO $pdo, array $user): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.title, p.slug
           FROM management_podcasts mp
           JOIN podcast p ON p.id = mp.podcast_id
          WHERE mp.management_id = :user_id
          ORDER BY p.title COLLATE NOCASE, p.id'
    );
    $stmt->execute([':user_id' => (int) ($user['id'] ?? 0)]);

    return [
        'id' => (int) ($user['id'] ?? 0),
        'first_name' => (string) ($user['first_name'] ?? ''),
        'last_name' => (string) ($user['last_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'is_active' => (int) ($user['is_active'] ?? 0) === 1,
        'podcasts' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'created_at' => $user['created_at'] ?? null,
        'updated_at' => $user['updated_at'] ?? null,
    ];
}

/**
 * @return array{first_name:string,last_name:string,email:string,password:string,is_active:int,podcast_ids:list<int>}
 */
function apiValidateUserPayload(PDO $pdo, array $body, ?array $existing): array
{
    $creating = $existing === null;
    $firstName = trim((string) ($body['first_name'] ?? ($existing['first_name'] ?? '')));
    $lastName = trim((string) ($body['last_name'] ?? ($existing['last_name'] ?? '')));
    $email = strtolower(trim((string) ($body['email'] ?? ($existing['email'] ?? ''))));
    $password = array_key_exists('password', $body) ? (string) $body['password'] : '';
    $isActive = array_key_exists('is_active', $body)
        ? apiUserBooleanValue($body['is_active'])
        : (int) ($existing['is_active'] ?? 1);

    if ($firstName === '' || $lastName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        apiError(__('Nombre, apellidos y email válido son obligatorios.'));
    }
    if (($creating || $password !== '') && strlen($password) < 8) {
        apiError(__('La contraseña debe tener al menos 8 caracteres.'));
    }

    $existingId = (int) ($existing['id'] ?? 0);
    $duplicate = $pdo->prepare('SELECT id FROM management WHERE (email = :email OR username = :email) AND id != :id LIMIT 1');
    $duplicate->execute([':email' => $email, ':id' => $existingId]);
    if ($duplicate->fetchColumn()) {
        apiError(__('Ya existe un usuario con ese email.'), 409);
    }

    if (array_key_exists('podcast_ids', $body) || array_key_exists('podcast_slugs', $body)) {
        $podcastIds = apiResolveUserPodcastAssignments($pdo, $body);
    } elseif ($creating) {
        apiError(__('Debes asignar al menos un podcast.'));
    } else {
        $stmt = $pdo->prepare('SELECT podcast_id FROM management_podcasts WHERE management_id = :id ORDER BY podcast_id');
        $stmt->execute([':id' => $existingId]);
        $podcastIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($podcastIds === []) {
        apiError(__('Debes asignar al menos un podcast.'));
    }

    return [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password' => $password,
        'is_active' => $isActive,
        'podcast_ids' => $podcastIds,
    ];
}

/** @return list<int> */
function apiResolveUserPodcastAssignments(PDO $pdo, array $body): array
{
    $ids = [];
    if (array_key_exists('podcast_ids', $body)) {
        if (!is_array($body['podcast_ids'])) {
            apiError(__('podcast_ids debe ser una lista.'));
        }
        foreach ($body['podcast_ids'] as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                apiError(__('La lista contiene podcasts no válidos.'));
            }
            $ids[] = (int) $id;
        }
    }

    if (array_key_exists('podcast_slugs', $body)) {
        if (!is_array($body['podcast_slugs'])) {
            apiError(__('podcast_slugs debe ser una lista.'));
        }
        $findSlug = $pdo->prepare('SELECT id FROM podcast WHERE slug = :slug LIMIT 1');
        foreach ($body['podcast_slugs'] as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                apiError(__('La lista contiene podcasts no válidos.'));
            }
            $findSlug->execute([':slug' => $slug]);
            $id = $findSlug->fetchColumn();
            if ($id === false) {
                apiError(__('La lista contiene podcasts no válidos.'));
            }
            $ids[] = (int) $id;
        }
    }

    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        return [];
    }

    $findId = $pdo->prepare('SELECT id FROM podcast WHERE id = :id LIMIT 1');
    foreach ($ids as $id) {
        $findId->execute([':id' => $id]);
        if ($findId->fetchColumn() === false) {
            apiError(__('La lista contiene podcasts no válidos.'));
        }
    }

    sort($ids);
    return $ids;
}

function apiUserBooleanValue(mixed $value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
        return (int) $value;
    }
    apiError(__('is_active debe ser true o false.'));
}

/** @param list<int> $podcastIds */
function apiReplaceUserPodcastAssignments(PDO $pdo, int $userId, array $podcastIds): void
{
    $pdo->prepare('DELETE FROM management_podcasts WHERE management_id = :id')->execute([':id' => $userId]);
    $insert = $pdo->prepare('INSERT INTO management_podcasts (management_id, podcast_id) VALUES (:user_id, :podcast_id)');
    foreach ($podcastIds as $podcastId) {
        $insert->execute([':user_id' => $userId, ':podcast_id' => $podcastId]);
    }
}
