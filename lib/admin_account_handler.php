<?php

declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/podcast_context.php';
require_once __DIR__ . '/access_control.php';

/**
 * Carga y actualiza la cuenta administradora global autenticada.
 *
 * @return array{form:array{username:string,first_name:string,last_name:string,email:string},error:string,notice:string}
 */
function loadAdminAccountData(string $dbPath): array
{
    $pdo = openPodcastDatabase($dbPath);
    $error = '';
    $notice = '';
    $userId = adminSessionUserId();
    $stmt = $pdo->prepare(
        'SELECT id, username, password, first_name, last_name, email
           FROM management WHERE id = :id AND is_global = 1 LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    $form = [
        'username' => (string) ($account['username'] ?? ''),
        'first_name' => (string) ($account['first_name'] ?? ''),
        'last_name' => (string) ($account['last_name'] ?? ''),
        'email' => (string) ($account['email'] ?? ''),
    ];

    if (!is_array($account)) {
        return ['form' => $form, 'error' => __('La cuenta administradora global no existe.'), 'notice' => ''];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return compact('form', 'error', 'notice');
    }

    csrf_verify();
    $form = [
        'username' => trim((string) ($_POST['username'] ?? '')),
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
    ];
    $currentPassword = (string) ($_POST['current_password'] ?? '');

    if ($form['username'] === '' || $currentPassword === '') {
        $error = __('El usuario de acceso y la contraseña actual son obligatorios.');
        return compact('form', 'error', 'notice');
    }
    if (strlen($form['username']) > 120) {
        $error = __('El usuario de acceso es demasiado largo.');
        return compact('form', 'error', 'notice');
    }
    if ($form['email'] !== '' && filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
        $error = __('El email del administrador no es válido.');
        return compact('form', 'error', 'notice');
    }

    $storedPassword = (string) ($account['password'] ?? '');
    $passwordIsHash = password_verify($currentPassword, $storedPassword);
    if (!$passwordIsHash && !hash_equals($storedPassword, $currentPassword)) {
        $error = __('La contraseña actual no es correcta.');
        return compact('form', 'error', 'notice');
    }

    $duplicate = $pdo->prepare(
        "SELECT id FROM management
          WHERE id != :id
            AND (username = :username OR email = :username
                 OR (:email != '' AND (username = :email OR email = :email)))
          LIMIT 1"
    );
    $duplicate->execute([
        ':id' => $userId,
        ':username' => $form['username'],
        ':email' => $form['email'],
    ]);
    if ($duplicate->fetchColumn()) {
        $error = __('Ya existe una cuenta con ese usuario o email.');
        return compact('form', 'error', 'notice');
    }

    try {
        $params = [
            ':username' => $form['username'],
            ':first_name' => $form['first_name'],
            ':last_name' => $form['last_name'],
            ':email' => $form['email'] !== '' ? $form['email'] : null,
            ':id' => $userId,
        ];
        $passwordSql = '';
        if (!$passwordIsHash) {
            $passwordSql = ', password = :password';
            $params[':password'] = password_hash($currentPassword, PASSWORD_DEFAULT);
        }
        $update = $pdo->prepare(
            "UPDATE management
                SET username = :username, first_name = :first_name, last_name = :last_name,
                    email = :email, updated_at = datetime('now')$passwordSql
              WHERE id = :id AND is_global = 1"
        );
        $update->execute($params);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Global administrator was not updated');
        }
        establishAdminSession([
            'id' => $userId,
            'username' => $form['username'],
            'is_global' => 1,
            'podcast_ids' => [],
        ]);
        $notice = __('Datos del administrador actualizados correctamente.');
    } catch (Throwable $e) {
        $error = __('No se pudo guardar la cuenta administradora.');
    }

    return compact('form', 'error', 'notice');
}
