<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/admin_account_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';
if (!isset($_SESSION['admin_user'])) { header('Location: admin.php'); exit; }
$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
requireGlobalAdminAccess();
header('X-Robots-Tag: noindex, nofollow, noarchive');
$data = loadAdminAccountData($dbPath);
extract($data);
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc(__('Cuenta del administrador global')) ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css"><link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
<?php $currentAdminPage = 'admin_account'; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-wrap"><main class="card">
  <h1><?= __('Cuenta del administrador global') ?></h1>
  <p><?= __('Define el usuario de acceso y los datos de la cuenta con control completo sobre el Multipodcast.') ?></p>
  <?php if ($error !== ''): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="notice"><?= esc($notice) ?></div><?php endif; ?>

  <form method="post" class="form-stack form-narrow" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <label><?= __('Usuario de acceso') ?><input name="username" value="<?= esc($form['username']) ?>" maxlength="120" required autofocus></label>
    <label><?= __('Nombre') ?><input name="first_name" value="<?= esc($form['first_name']) ?>"></label>
    <label><?= __('Apellidos') ?><input name="last_name" value="<?= esc($form['last_name']) ?>"></label>
    <label><?= __('Email') ?><input type="email" name="email" value="<?= esc($form['email']) ?>"></label>
    <label><?= __('Contraseña actual para confirmar') ?><input type="password" name="current_password" required></label>
    <div class="actions"><button class="btn" type="submit"><?= __('Guardar administrador') ?></button></div>
  </form>
</main></div>
</body></html>
