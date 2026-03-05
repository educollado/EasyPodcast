<?php

declare(strict_types=1);

require_once __DIR__ . '/csrf.php';

/**
 * Procesa el cambio de contraseña del usuario en sesión.
 *
 * @return array{error:string, notice:string}
 */
function loadChangePasswordData(string $dbPath): array
{
    $error  = '';
    $notice = '';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return compact('error', 'notice');
    }

    csrf_verify();

    $username    = (string) ($_SESSION['admin_user'] ?? '');
    $current     = (string) ($_POST['current_password'] ?? '');
    $new         = (string) ($_POST['new_password'] ?? '');
    $confirm     = (string) ($_POST['new_password_confirm'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        $error = 'Completa todos los campos.';
        return compact('error', 'notice');
    }

    if ($new !== $confirm) {
        $error = 'La nueva contraseña y su confirmación no coinciden.';
        return compact('error', 'notice');
    }

    if (strlen($new) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
        return compact('error', 'notice');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT id, password FROM management WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'Usuario no encontrado.';
            return compact('error', 'notice');
        }

        $stored = (string) $row['password'];
        if (!password_verify($current, $stored) && !hash_equals($stored, $current)) {
            $error = 'La contraseña actual no es correcta.';
            return compact('error', 'notice');
        }

        $upd = $pdo->prepare(
            "UPDATE management SET password = :p, updated_at = datetime('now') WHERE id = :id"
        );
        $upd->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => (int) $row['id']]);

        $notice = 'Contraseña actualizada correctamente.';
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en change_password.php: ' . $e->getMessage() . "\n";
        exit;
    }

    return compact('error', 'notice');
}
