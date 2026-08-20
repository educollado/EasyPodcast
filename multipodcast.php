<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_service.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';
if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
requireGlobalAdminAccess();
header('X-Robots-Tag: noindex, nofollow, noarchive');

$multipodcastDashboardPdo = openPodcastDatabase($dbPath);
$multipodcastDashboardSettings = loadAppSettings($multipodcastDashboardPdo);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'set_summary_language') {
    csrf_verify();
    $summaryLanguage = trim((string) ($_POST['summary_language'] ?? ''));
    if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $summaryLanguage)
        && is_file(__DIR__ . '/locale/' . $summaryLanguage . '.po')) {
        $stmt = $multipodcastDashboardPdo->prepare('UPDATE app_settings SET summary_language = :language WHERE id = 1');
        $stmt->execute([':language' => $summaryLanguage]);
        clearWebCache();
    }
    header('Location: multipodcast.php');
    exit;
}
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
      <h1><?= __('Panel de administración del Multipodcast') ?></h1>
      <p><?= __('Sesión iniciada como') ?> <strong><?= esc((string) $_SESSION['admin_user']) ?></strong>.</p>

      <div class="admin-cards">
        <?php
          $multipodcastDashboardLocaleLabels = [
              'ca_ES' => 'Català', 'de_DE' => 'Deutsch', 'en_US' => 'English', 'es_ES' => 'Español',
              'fr_FR' => 'Français', 'gl_ES' => 'Galego', 'it_IT' => 'Italiano', 'pt_PT' => 'Português',
          ];
          $multipodcastDashboardLocaleFiles = glob(__DIR__ . '/locale/*.po') ?: [];
          sort($multipodcastDashboardLocaleFiles);
        ?>
        <form method="post" action="multipodcast.php" class="admin-card admin-card-form">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="set_summary_language">
          <div class="admin-card-icon">🌐</div>
          <h2><?= __('Idioma de Multipodcast') ?></h2>
          <select name="summary_language" data-submit-on-change="1">
            <?php foreach ($multipodcastDashboardLocaleFiles as $multipodcastDashboardLocaleFile):
              $multipodcastDashboardLocale = basename($multipodcastDashboardLocaleFile, '.po');
            ?>
              <option value="<?= esc($multipodcastDashboardLocale) ?>" <?= $multipodcastDashboardSettings['summary_language'] === $multipodcastDashboardLocale ? 'selected' : '' ?>><?= esc($multipodcastDashboardLocaleLabels[$multipodcastDashboardLocale] ?? $multipodcastDashboardLocale) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <a class="admin-card" href="multipodcast_management.php">
          <div class="admin-card-icon">🎧</div>
          <h2><?= __('Multipodcast') ?></h2>
          <p><?= __('Configuración Multipodcast') ?></p>
        </a>
        <a class="admin-card" href="podcasts_management.php">
          <div class="admin-card-icon">🎙️</div>
          <h2><?= __('Podcasts') ?></h2>
          <p><?= __('Crea, selecciona y configura los podcasts de la instalación') ?></p>
        </a>
        <a class="admin-card" href="users_management.php">
          <div class="admin-card-icon">👥</div>
          <h2><?= __('Usuarios') ?></h2>
          <p><?= __('Asigna a cada usuario uno o varios podcasts') ?></p>
        </a>
        <a class="admin-card" href="admin_account.php">
          <div class="admin-card-icon">🛡️</div>
          <h2><?= __('Administrador global') ?></h2>
          <p><?= __('Configura la cuenta que administra toda la instalación') ?></p>
        </a>
        <a class="admin-card" href="media_cleanup.php">
          <div class="admin-card-icon">🧹</div>
          <h2><?= __('Limpiar') ?></h2>
          <p><?= __('Borra audios e imágenes que no usa ningún episodio') ?></p>
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
