<?php

declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/podcast_context.php';

/** Gestiona las cuentas limitadas a un podcast. */
function loadUsersManagementData(string $dbPath): array
{
    $pdo = openPodcastDatabase($dbPath);
    $error = '';
    $notice = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_verify();
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save') {
                savePodcastUser($pdo);
                $notice = __('Usuario guardado correctamente.');
            } elseif ($action === 'delete') {
                deletePodcastUser($pdo, (int) ($_POST['user_id'] ?? 0));
                $notice = __('Usuario eliminado correctamente.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $users = $pdo->query(
        "SELECT m.id, m.first_name, m.last_name, m.email, m.is_active,
                GROUP_CONCAT(mp.podcast_id, ',') AS podcast_ids,
                GROUP_CONCAT(p.title, ', ') AS podcast_titles
           FROM management m
           LEFT JOIN management_podcasts mp ON mp.management_id = m.id
           LEFT JOIN podcast p ON p.id = mp.podcast_id
          WHERE m.is_global = 0
          GROUP BY m.id
          ORDER BY m.last_name COLLATE NOCASE, m.first_name COLLATE NOCASE, m.email COLLATE NOCASE"
    )->fetchAll();
    foreach ($users as &$user) {
        $user['podcast_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) ($user['podcast_ids'] ?? '')))));
    }
    unset($user);
    $podcasts = $pdo->query('SELECT id, title FROM podcast ORDER BY title COLLATE NOCASE')->fetchAll();

    return compact('users', 'podcasts', 'error', 'notice');
}

function savePodcastUser(PDO $pdo): void
{
    $id = (int) ($_POST['user_id'] ?? 0);
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $podcastIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['podcast_ids'] ?? [])))));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($firstName === '' || $lastName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException(__('Nombre, apellidos y email válido son obligatorios.'));
    }
    if ($podcastIds === []) {
        throw new RuntimeException(__('Selecciona al menos un podcast.'));
    }
    foreach ($podcastIds as $podcastId) {
        if (podcastById($pdo, $podcastId) === null) {
            throw new RuntimeException(__('Selecciona únicamente podcasts válidos.'));
        }
    }
    if ($id === 0 && strlen($password) < 8) {
        throw new RuntimeException(__('La contraseña debe tener al menos 8 caracteres.'));
    }
    if ($password !== '' && strlen($password) < 8) {
        throw new RuntimeException(__('La contraseña debe tener al menos 8 caracteres.'));
    }

    $duplicate = $pdo->prepare('SELECT id FROM management WHERE (email = :email OR username = :email) AND id != :id LIMIT 1');
    $duplicate->execute([':email' => $email, ':id' => $id]);
    if ($duplicate->fetchColumn()) {
        throw new RuntimeException(__('Ya existe un usuario con ese email.'));
    }

    if ($id > 0) {
        $existing = $pdo->prepare('SELECT id FROM management WHERE id = :id AND is_global = 0 LIMIT 1');
        $existing->execute([':id' => $id]);
        if (!$existing->fetchColumn()) {
            throw new RuntimeException(__('El usuario no existe.'));
        }
        $sql = "UPDATE management SET first_name = :first_name, last_name = :last_name, email = :email,
                    username = :email, is_active = :is_active, updated_at = datetime('now')";
        $binds = [':first_name' => $firstName, ':last_name' => $lastName, ':email' => $email, ':is_active' => $isActive, ':id' => $id];
        if ($password !== '') {
            $sql .= ', password = :password';
            $binds[':password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare($sql . ' WHERE id = :id AND is_global = 0')->execute($binds);
            replaceUserPodcastAssignments($pdo, $id, $podcastIds);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO management (username, password, first_name, last_name, email, is_global, is_active)
             VALUES (:email, :password, :first_name, :last_name, :email, 0, :is_active)"
        );
        $stmt->execute([
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':is_active' => $isActive,
        ]);
        replaceUserPodcastAssignments($pdo, (int) $pdo->lastInsertId(), $podcastIds);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function replaceUserPodcastAssignments(PDO $pdo, int $userId, array $podcastIds): void
{
    $pdo->prepare('DELETE FROM management_podcasts WHERE management_id = :id')->execute([':id' => $userId]);
    $insert = $pdo->prepare('INSERT INTO management_podcasts (management_id, podcast_id) VALUES (:user_id, :podcast_id)');
    foreach ($podcastIds as $podcastId) {
        $insert->execute([':user_id' => $userId, ':podcast_id' => (int) $podcastId]);
    }
}

function deletePodcastUser(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException(__('El usuario no existe.'));
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM api_tokens WHERE user_id = :id')->execute([':id' => $id]);
        $stmt = $pdo->prepare('DELETE FROM management WHERE id = :id AND is_global = 0');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException(__('El usuario no existe.'));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
