<?php

declare(strict_types=1);

// Punto de entrada de administración:
// - primera ejecución: crear usuario admin inicial
// - siguientes ejecuciones: login/logout y acceso a gestión

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/admin_query.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$isLoggedIn = isset($_SESSION['admin_user']);

$data = loadAdminData($dbPath);
extract($data); // adminCount, isSetupMode, error, notice
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
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
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
