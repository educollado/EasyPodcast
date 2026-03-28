<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/change_password_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadChangePasswordData($dbPath);
extract($data); // error, notice
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Cambiar contraseña') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'password'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Cambiar contraseña') ?></h1>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="change_password.php" autocomplete="off" style="display:grid;gap:.75rem;max-width:420px;">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <label>
          <?= __('Contraseña actual') ?>
          <input type="password" name="current_password" required autofocus>
        </label>
        <label>
          <?= __('Nueva contraseña') ?>
          <input type="password" name="new_password" required minlength="8">
        </label>
        <label>
          <?= __('Repite la nueva contraseña') ?>
          <input type="password" name="new_password_confirm" required minlength="8">
        </label>
        <div class="actions">
          <button class="btn" type="submit"><?= __('Cambiar contraseña') ?></button>
        </div>
      </form>
    </main>
  </div>
</body>
</html>
