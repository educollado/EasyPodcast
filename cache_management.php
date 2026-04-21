<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_management_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadCacheManagementData($dbPath);
extract($data); // cacheEnabled, error, notice
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Gestión de Caché') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'cache'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Gestión de Caché') ?></h1>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <section>
        <h2><?= __('Caché web') ?></h2>
        <p><?= __('Almacena la salida HTML de portada, episodios, feed y sitemap para servir respuestas más rápido.') ?></p>

        <form method="post" action="cache_management.php">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="cache_action" value="save_settings">
          <label class="inline-checkbox">
            <input type="checkbox" name="cache_enabled" value="1" <?= $cacheEnabled === '1' ? 'checked' : '' ?>>
            <span><?= __('Habilitar caché pública en /cache') ?></span>
            <small><?= __('Aplica a portada, episodio, feed y sitemap.') ?></small>
          </label>
          <div class="actions" style="margin-top:.8rem;">
            <button class="btn" type="submit"><?= __('Guardar') ?></button>
          </div>
        </form>

        <form method="post" action="cache_management.php" style="margin-top:.5rem;">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="cache_action" value="clear_cache">
          <div class="actions">
            <button class="btn back" type="submit"><?= __('Borrar caché web') ?></button>
          </div>
        </form>
      </section>

      <section style="margin-top:1.5rem;">
        <h2><?= __('Imágenes generadas') ?></h2>
        <p><?= __('Variantes redimensionadas de las imágenes del podcast y episodios, almacenadas en %s. Regenerarlas borra las actuales y las vuelve a crear en los tamaños 80, 144 y 220 px.', '<code>/images/generated/</code>') ?></p>

        <form method="post" action="cache_management.php">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="cache_action" value="regenerate_images">
          <div class="actions">
            <button class="btn" type="submit"><?= __('Regenerar imágenes') ?></button>
          </div>
        </form>
      </section>
    </main>
  </div>
</body>
</html>
