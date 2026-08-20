<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/users_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';
if (!isset($_SESSION['admin_user'])) { header('Location: admin.php'); exit; }
$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
requireGlobalAdminAccess();
header('X-Robots-Tag: noindex, nofollow, noarchive');
$data = loadUsersManagementData($dbPath);
extract($data);
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Usuarios') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css"><link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
<?php $currentAdminPage = 'users'; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-wrap"><main class="card">
  <h1><?= __('Usuarios') ?></h1>
  <p><?= __('Crea cuentas limitadas a la gestión de uno o varios podcasts.') ?></p>
  <?php if ($error !== ''): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="notice"><?= esc($notice) ?></div><?php endif; ?>

  <section><h2><?= __('Crear usuario') ?></h2>
    <form method="post" class="form-stack" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <div class="grid two">
        <label><?= __('Nombre') ?><input name="first_name" required></label>
        <label><?= __('Apellidos') ?><input name="last_name" required></label>
        <label><?= __('Email') ?><input type="email" name="email" required></label>
        <label><?= __('Contraseña') ?><input type="password" name="password" minlength="8" required></label>
        <fieldset class="podcast-assignment-fieldset"><legend><?= __('Podcasts que puede gestionar') ?></legend><?php foreach ($podcasts as $podcastOption): ?><label class="inline-checkbox"><input type="checkbox" name="podcast_ids[]" value="<?= (int) $podcastOption['id'] ?>"><span><?= esc((string) $podcastOption['title']) ?></span></label><?php endforeach; ?></fieldset>
        <label class="inline-checkbox"><input type="checkbox" name="is_active" value="1" checked><span><?= __('Usuario activo') ?></span></label>
      </div><div class="actions"><button class="btn" type="submit"><?= __('Crear usuario') ?></button></div>
    </form>
  </section>

  <section class="section-gap-xl"><h2><?= __('Usuarios existentes') ?></h2>
  <?php if (!$users): ?><p><?= __('Todavía no hay usuarios de podcast.') ?></p><?php endif; ?>
  <?php foreach ($users as $user): ?>
    <form method="post" class="card form-stack section-gap-md" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
      <div class="grid two">
        <label><?= __('Nombre') ?><input name="first_name" value="<?= esc((string) $user['first_name']) ?>" required></label>
        <label><?= __('Apellidos') ?><input name="last_name" value="<?= esc((string) $user['last_name']) ?>" required></label>
        <label><?= __('Email') ?><input type="email" name="email" value="<?= esc((string) $user['email']) ?>" required></label>
        <label><?= __('Nueva contraseña (opcional)') ?><input type="password" name="password" minlength="8"></label>
        <fieldset class="podcast-assignment-fieldset"><legend><?= __('Podcasts que puede gestionar') ?></legend><?php foreach ($podcasts as $podcastOption): ?><label class="inline-checkbox"><input type="checkbox" name="podcast_ids[]" value="<?= (int) $podcastOption['id'] ?>" <?= in_array((int) $podcastOption['id'], $user['podcast_ids'], true) ? 'checked' : '' ?>><span><?= esc((string) $podcastOption['title']) ?></span></label><?php endforeach; ?></fieldset>
        <label class="inline-checkbox"><input type="checkbox" name="is_active" value="1" <?= (int) $user['is_active'] === 1 ? 'checked' : '' ?>><span><?= __('Usuario activo') ?></span></label>
      </div><div class="actions"><button class="btn" type="submit"><?= __('Guardar') ?></button></div>
    </form>
    <form method="post" class="section-gap-sm" data-confirm-message="<?= esc(__('¿Eliminar este usuario y todos sus tokens API?')) ?>">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
      <button class="btn btn-danger" type="submit"><?= __('Eliminar usuario') ?></button>
    </form>
  <?php endforeach; ?></section>
</main></div></body></html>
