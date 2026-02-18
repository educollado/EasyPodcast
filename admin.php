<?php

declare(strict_types=1);

// Punto de entrada de administración:
// - primera ejecución: crear usuario admin inicial
// - siguientes ejecuciones: login/logout y acceso a gestión

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/feed_builder.php';

// ---------------------------------------------------------------------------
// Bootstrap de sesión y contexto
// ---------------------------------------------------------------------------

session_start();

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
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

    // Exportación de base de datos (solo sesión activa).
    if ($isLoggedIn && isset($_GET['action']) && $_GET['action'] === 'export_db') {
        if (!is_file($dbPath)) {
            $error = 'No se encontró la base de datos para exportar.';
        } else {
            $downloadName = 'easy_podcast_backup_' . date('Ymd_His') . '.sqlite';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . (string) filesize($dbPath));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            readfile($dbPath);
            exit;
        }
    }

    // Importación de base de datos (solo sesión activa).
    if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['db_action'] ?? '') === 'import_db') {
        if (!isset($_FILES['db_file']) || !is_array($_FILES['db_file'])) {
            $error = 'Selecciona un archivo de base de datos.';
        } else {
            $uploadError = (int) ($_FILES['db_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            $uploadedPath = (string) ($_FILES['db_file']['tmp_name'] ?? '');
            $originalName = strtolower((string) ($_FILES['db_file']['name'] ?? ''));
            $validExtension = preg_match('/\.(sqlite|db)$/', $originalName) === 1;

            if ($uploadError !== UPLOAD_ERR_OK || $uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
                $error = 'No se pudo subir el archivo.';
            } elseif (!$validExtension) {
                $error = 'El archivo debe tener extensión .sqlite o .db.';
            } else {
                $probe = new PDO('sqlite:' . $uploadedPath);
                $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $hasPodcastTable = (int) $probe
                    ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'podcast'")
                    ->fetchColumn();
                $hasEpisodesTable = (int) $probe
                    ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'episodes'")
                    ->fetchColumn();

                if ($hasPodcastTable === 0 || $hasEpisodesTable === 0) {
                    $error = 'La base de datos importada no parece válida para EasyPodcast.';
                } elseif (!class_exists('SQLite3')) {
                    $error = 'La extensión SQLite3 no está disponible en este servidor.';
                } else {
                    $backupDir = __DIR__ . '/backups';
                    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                        $error = 'No se pudo crear el directorio de backups.';
                    } else {
                        $backupPath = $backupDir . '/podcast-before-import-' . date('Ymd_His') . '.sqlite';
                        if (!copy($dbPath, $backupPath)) {
                            $error = 'No se pudo crear el backup previo de seguridad.';
                        } else {
                            // Cierra conexiones PDO para evitar bloqueos antes de restaurar.
                            $probe = null;
                            $pdo = null;

                            $sourceDb = new SQLite3($uploadedPath, SQLITE3_OPEN_READONLY);
                            $targetDb = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
                            $importOk = $sourceDb->backup($targetDb);
                            $sourceDb->close();
                            $targetDb->close();

                            if (!$importOk) {
                                $error = 'Falló la importación de la base de datos.';
                            } else {
                                $pdo = new PDO('sqlite:' . $dbPath);
                                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                                try {
                                    writePodcastFeedFile($pdo, __DIR__ . '/feed.xml', resolveFeedSelfHref($pdo));
                                    $notice = 'Base de datos importada correctamente. Se creó backup y se regeneró feed.xml.';
                                } catch (Throwable $feedError) {
                                    $notice = 'Base de datos importada correctamente. Se creó backup, pero no se pudo regenerar feed.xml.';
                                }

                                $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM management')->fetchColumn();
                            }
                        }
                    }
                }
            }
        }
    // Un único formulario maneja:
    // - setup inicial (no existe usuario admin)
    // - login normal (ya existe al menos uno)
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        <a class="btn logout" href="admin.php?logout=1">Cerrar sesión</a>
      </div>
      <section class="db-tools" aria-label="Herramientas de base de datos">
        <a class="btn db-export" href="admin.php?action=export_db">Exportar base de datos</a>
        <form class="db-import-form" method="post" action="admin.php" enctype="multipart/form-data">
          <input type="hidden" name="db_action" value="import_db">
          <label for="db_file">Importar base de datos</label>
          <input id="db_file" type="file" name="db_file" accept=".sqlite,.db" required>
          <button class="btn db-import" type="submit">Importar base de datos</button>
        </form>
      </section>
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
