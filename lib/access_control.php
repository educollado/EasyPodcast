<?php

declare(strict_types=1);

/** Guarda en sesión la identidad y el alcance administrativo de una cuenta. */
function establishAdminSession(array $user): void
{
    $_SESSION['admin_user'] = (string) $user['username'];
    $_SESSION['admin_user_id'] = (int) $user['id'];
    $_SESSION['admin_is_global'] = (int) ($user['is_global'] ?? 0);
    $_SESSION['admin_podcast_ids'] = array_values(array_map('intval', $user['podcast_ids'] ?? []));
}

/** Refresca permisos desde BD en cada petición autenticada. */
function refreshAdminSession(PDO $pdo): bool
{
    $username = (string) ($_SESSION['admin_user'] ?? '');
    if ($username === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id, username, is_global, is_active FROM management WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int) ($user['is_active'] ?? 1) !== 1) {
        $_SESSION = [];
        return false;
    }
    $assigned = $pdo->prepare('SELECT podcast_id FROM management_podcasts WHERE management_id = :id ORDER BY podcast_id');
    $assigned->execute([':id' => (int) $user['id']]);
    $user['podcast_ids'] = array_map('intval', $assigned->fetchAll(PDO::FETCH_COLUMN));
    establishAdminSession($user);
    return true;
}

function adminSessionIsGlobal(): bool
{
    // Compatibilidad con sesiones creadas antes de introducir roles: las
    // cuentas preexistentes se migran como administradoras globales.
    return (int) ($_SESSION['admin_is_global'] ?? 1) === 1;
}

function adminSessionUserId(): int
{
    return (int) ($_SESSION['admin_user_id'] ?? 0);
}

function adminSessionPodcastIds(): array
{
    return array_values(array_filter(array_map('intval', (array) ($_SESSION['admin_podcast_ids'] ?? [])), static fn (int $id): bool => $id > 0));
}

/** Restringe una página a la cuenta administradora global. */
function requireGlobalAdminAccess(): void
{
    if (adminSessionIsGlobal()) {
        return;
    }
    header('Location: admin.php');
    exit;
}
