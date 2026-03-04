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
  <title>Administración</title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
</head>
<body class="<?= $isLoggedIn ? '' : 'login-page' ?>">
  <?php if ($isLoggedIn): ?>
    <?php $currentAdminPage = 'dashboard'; require __DIR__ . '/admin_nav.php'; ?>
    <div class="admin-wrap">
      <main class="card">
        <h1>Panel de administración</h1>
        <p>Sesión iniciada como <strong><?= esc((string) $_SESSION['admin_user']) ?></strong>.</p>

        <?php if ($error !== ''): ?>
          <div class="error"><?= esc($error) ?></div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
          <div class="notice"><?= esc($notice) ?></div>
        <?php endif; ?>

        <div class="admin-cards">
          <a class="admin-card" href="podcast_management.php">
            <div class="admin-card-icon">🎙</div>
            <h2>Podcast</h2>
            <p>Metadatos del canal, imagen y categorías</p>
          </a>
          <a class="admin-card" href="episodes_management.php">
            <div class="admin-card-icon">📻</div>
            <h2>Capítulos</h2>
            <p>Lista, edita y borra episodios</p>
          </a>
          <a class="admin-card" href="add_episode.php">
            <div class="admin-card-icon">➕</div>
            <h2>Añadir capítulo</h2>
            <p>Sube un nuevo episodio al podcast</p>
          </a>
          <a class="admin-card" href="backups.php">
            <div class="admin-card-icon">💾</div>
            <h2>Backups</h2>
            <p>Exporta e importa la base de datos</p>
          </a>
          <a class="admin-card" href="/" target="_blank" rel="noopener">
            <div class="admin-card-icon">🌐</div>
            <h2>Ver podcast</h2>
            <p>Abre la web pública en una pestaña nueva</p>
          </a>
        </div>

        <div class="actions" style="margin-top:1.5rem; justify-content:flex-end;">
          <a class="btn logout" href="admin.php?logout=1">Cerrar sesión</a>
        </div>
      </main>
    </div>
  <?php else: ?>
    <main class="card">
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

      <form method="post" action="admin.php" autocomplete="off" style="display:grid;gap:.75rem;">
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

        <button class="btn" type="submit"><?= $isSetupMode ? 'Crear usuario y entrar' : 'Entrar' ?></button>
      </form>
    </main>
  <?php endif; ?>
</body>
</html>
