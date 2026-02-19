<?php

declare(strict_types=1);

// Punto de entrada de administración:
// - primera ejecución: crear usuario admin inicial
// - siguientes ejecuciones: login/logout y acceso a gestión

require_once __DIR__ . '/canonical_redirect.php';

// ---------------------------------------------------------------------------
// Bootstrap de sesión y contexto
// ---------------------------------------------------------------------------

session_start();

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');
$error = '';
$notice = '';
$isLoggedIn = isset($_SESSION['admin_user']);

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Administración Podcast</title>
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
  <main class="card">
    <?php if ($isLoggedIn): ?>
      <h1>Panel de administración</h1>
      <p>Sesión iniciada como <strong><?= esc((string) $_SESSION['admin_user']) ?></strong>.</p>
      <p>Desde aquí puedes gestionar metadatos del podcast y crear capítulos.</p>
      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>
      <div class="actions">
        <a class="btn manage" href="podcast_management.php">Gestión Podcast</a>
        <a class="btn manage" href="episodes_management.php">Gestión Capítulos</a>
        <a class="btn manage" href="/">Visitar podcast</a>
        <a class="btn manage" href="backups.php">Copias de seguridad</a>
        <a class="btn logout" href="admin.php?logout=1">Cerrar sesión</a>
      </div>
    <?php else: ?>
      <h1><?= $isSetupMode ? 'Configuración inicial' : 'Acceso administrador' ?></h1>
      <p>
        <?= $isSetupMode
            ? 'No hay usuario administrador. Crea el primero para proteger el panel.'
            : 'Introduce tus credenciales para entrar al panel de administración.' ?>
      </p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="admin.php" autocomplete="off">
        <label>
          Usuario
          <input type="text" name="username" maxlength="120" required>
        </label>
        <label>
          Contraseña
          <input type="password" name="password" required>
        </label>

        <?php if ($isSetupMode): ?>
          <label>
            Repite la contraseña
            <input type="password" name="password_confirm" required>
          </label>
        <?php endif; ?>

        <button type="submit"><?= $isSetupMode ? 'Crear usuario y entrar' : 'Entrar' ?></button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
