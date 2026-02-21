<?php

declare(strict_types=1);

// Lógica de BD y autenticación para admin.php:
// - crea tabla management si no existe
// - gestiona logout, setup inicial y login normal

require_once __DIR__ . '/csrf.php';

/**
 * Procesa la sesión de administración (login, setup, logout) y retorna
 * los datos necesarios para renderizar la vista.
 *
 * Efectos secundarios: puede destruir sesión, iniciar sesión, redirigir y salir.
 *
 * @return array{adminCount:int, isSetupMode:bool, error:string, notice:string}
 */
function loadAdminData(string $dbPath): array
{
    $error = '';
    $notice = '';
    $adminCount = 0;

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mantiene una única tabla de gestión para credenciales admin.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS management (
              id INTEGER PRIMARY KEY,
              username TEXT NOT NULL UNIQUE,
              password TEXT NOT NULL,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            )"
        );

        $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM management')->fetchColumn();

        // Acción explícita de logout.
        if (isset($_GET['logout'])) {
            $_SESSION = [];
            session_destroy();
            header('Location: admin.php');
            exit;
        }

        // Un único formulario maneja:
        // - setup inicial (no existe usuario admin)
        // - login normal (ya existe al menos uno)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($adminCount === 0) {
                // Modo setup: crea la primera cuenta administradora.
                $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

                if ($username === '' || $password === '' || $passwordConfirm === '') {
                    $error = 'Completa usuario, contraseña y confirmación.';
                } elseif ($password !== $passwordConfirm) {
                    $error = 'Las contraseñas no coinciden.';
                } elseif (strlen($password) < 8) {
                    $error = 'La contraseña debe tener al menos 8 caracteres.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO management (username, password) VALUES (:username, :password)');
                    $stmt->execute([
                        ':username' => $username,
                        ':password' => $hash,
                    ]);

                    $_SESSION['admin_user'] = $username;
                    $notice = 'Usuario administrador creado correctamente.';
                    $adminCount = 1;
                }
            } else {
                // Modo normal: autentica un usuario admin existente.
                if ($username === '' || $password === '') {
                    $error = 'Introduce usuario y contraseña.';
                } else {
                    $stmt = $pdo->prepare('SELECT id, username, password FROM management WHERE username = :username LIMIT 1');
                    $stmt->execute([':username' => $username]);
                    $row = $stmt->fetch();

                    if (!$row) {
                        $error = 'Credenciales inválidas.';
                    } else {
                        $stored = (string) $row['password'];
                        // El fallback con hash_equals permite migrar contraseñas legacy en texto plano.
                        $valid = password_verify($password, $stored) || hash_equals($stored, $password);

                        if (!$valid) {
                            $error = 'Credenciales inválidas.';
                        } else {
                            // Si una contraseña legacy coincide por fallback, se rehashea de forma transparente.
                            if (!password_verify($password, $stored)) {
                                $rehashStmt = $pdo->prepare(
                                    'UPDATE management SET password = :password, updated_at = datetime(\'now\') WHERE id = :id'
                                );
                                $rehashStmt->execute([
                                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                                    ':id' => (int) $row['id'],
                                ]);
                            }

                            $_SESSION['admin_user'] = (string) $row['username'];
                            header('Location: admin.php');
                            exit;
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en admin.php: ' . $e->getMessage() . "\n";
        exit;
    }

    $isSetupMode = ($adminCount === 0);

    return compact('adminCount', 'isSetupMode', 'error', 'notice');
}
