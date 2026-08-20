<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';
if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$multipodcastDashboardPdo = openPodcastDatabase($dbPath);
$multipodcastDashboardSettings = loadAppSettings($multipodcastDashboardPdo);
$multipodcastDashboardTheme = isset(ADMIN_THEMES[$multipodcastDashboardSettings['summary_theme']])
    ? $multipodcastDashboardSettings['summary_theme']
    : 'easypodcast';
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc($multipodcastDashboardTheme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Multipodcast') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'multipodcast'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Multipodcast') ?></h1>
      <p><?= __('Sesión iniciada como') ?> <strong><?= esc((string) $_SESSION['admin_user']) ?></strong>.</p>

      <div class="admin-cards">
        <a class="admin-card" href="multipodcast_management.php">
          <div class="admin-card-icon">🎧</div>
          <h2><?= __('Multipodcast') ?></h2>
          <p><?= __('Crea, selecciona y configura los podcasts de la instalación') ?></p>
        </a>
        <a class="admin-card" href="cache_management.php">
          <div class="admin-card-icon">⚡</div>
          <h2><?= __('Caché') ?></h2>
          <p><?= __('Habilita la caché, bórrala o regenera imágenes') ?></p>
        </a>
        <a class="admin-card" href="update.php">
          <div class="admin-card-icon">⬆️</div>
          <h2><?= __('Actualizar') ?></h2>
          <p><?= __('Comprueba e instala nuevas versiones') ?></p>
        </a>
        <a class="admin-card" href="change_password.php">
          <div class="admin-card-icon">🔑</div>
          <h2><?= __('Contraseña') ?></h2>
          <p><?= __('Cambia la contraseña de acceso al panel') ?></p>
        </a>
        <a class="admin-card" href="twofa_management.php">
          <div class="admin-card-icon">🔐</div>
          <h2><?= __('2FA') ?></h2>
          <p><?= __('Autenticación en dos pasos con código TOTP') ?></p>
        </a>
        <a class="admin-card" href="backups.php">
          <div class="admin-card-icon">💾</div>
          <h2><?= __('Backups') ?></h2>
          <p><?= __('Exporta e importa la base de datos') ?></p>
        </a>
        <a class="admin-card" href="api_tokens.php">
          <div class="admin-card-icon">🔌</div>
          <h2>API</h2>
          <p><?= __('Genera y revoca tokens para la API REST') ?></p>
        </a>
      </div>
    </main>
  </div>
</body>
</html>
