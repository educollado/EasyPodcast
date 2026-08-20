<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/podcasts_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';
if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}
$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

if (isset($_GET['download_backup']) && isset($_SESSION['podcast_backup_file'])) {
    $fileName = basename((string) $_SESSION['podcast_backup_file']);
    if (hash_equals($fileName, basename((string) $_GET['download_backup']))) {
        $path = __DIR__ . '/backups/' . $fileName;
        if (is_file($path)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
    }
}

$data = loadPodcastsManagementData($dbPath, __DIR__);
extract($data);
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Podcasts') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
<?php $currentAdminPage = 'podcasts'; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-wrap"><main class="card">
  <h1><?= __('Podcasts') ?></h1>
  <?php if ($error !== ''): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="notice"><?= esc($notice) ?></div><?php endif; ?>
  <?php if ($backup_file !== ''): ?><p><a class="button" href="podcasts.php?download_backup=<?= esc(rawurlencode($backup_file)) ?>"><?= __('Descargar copia de seguridad') ?></a></p><?php endif; ?>

  <h2><?= __('Configuración Multipodcast') ?></h2>
  <form method="post" action="podcasts.php">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_settings">
    <label class="inline-checkbox multipodcast-toggle">
      <input type="checkbox" name="multipodcast_enabled" value="1" <?= $settings['multipodcast_enabled'] === 1 ? 'checked' : '' ?>>
      <span><?= __('Activar Multipodcast') ?></span>
    </label>
    <p class="multipodcast-warning" role="note"><?= __('Al activarlo, cada podcast usará su propio directorio y cambiarán sus URLs públicas. La portada principal mostrará el resumen o el podcast elegido; las URLs antiguas de episodios solo se redirigirán si eliges un podcast para la portada.') ?></p>
    <label for="homepage_podcast_id"><?= __('Contenido de la portada principal') ?></label>
    <select id="homepage_podcast_id" name="homepage_podcast_id">
      <option value=""><?= __('Resumen de todos los podcasts') ?></option>
      <?php foreach ($podcasts as $podcast): ?>
        <option value="<?= (int) $podcast['id'] ?>" <?= $settings['homepage_podcast_id'] === (int) $podcast['id'] ? 'selected' : '' ?>><?= esc((string) $podcast['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit"><?= __('Guardar configuración') ?></button>
  </form>

  <h2><?= __('Podcasts disponibles') ?></h2>
  <div class="admin-cards">
  <?php foreach ($podcasts as $podcast): ?>
    <section class="admin-card">
      <h3><?= esc((string) $podcast['title']) ?></h3>
      <p><?= __('Directorio:') ?> <code>/<?= esc((string) ($podcast['slug'] ?? '')) ?>/</code></p>
      <p><?= __('%d capítulos', (int) $podcast['episode_count']) ?></p>
      <p><a class="button" href="admin.php?podcast=<?= esc(rawurlencode((string) $podcast['slug'])) ?>&amp;manage=1"><?= __('Administrar podcast') ?></a></p>
      <form method="post" action="podcasts.php">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="action" value="rename_slug"><input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
        <label><?= __('Directorio del podcast') ?><input name="slug" value="<?= esc((string) ($podcast['slug'] ?? '')) ?>" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*"></label>
        <button type="submit"><?= __('Cambiar directorio') ?></button>
      </form>
      <?php if (count($podcasts) > 1): ?>
      <form method="post" action="podcasts.php" data-confirm-message="<?= esc(__('Se creará una copia ZIP y se borrarán definitivamente el podcast, sus capítulos, estadísticas y medios. ¿Continuar?')) ?>">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
        <label><?= __('Escribe el título para confirmar') ?><input name="confirm_title" required autocomplete="off"></label>
        <button class="danger" type="submit"><?= __('Crear backup y borrar podcast') ?></button>
      </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>

  <h2><?= __('Crear un podcast nuevo') ?></h2>
  <form method="post" action="podcasts.php">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label><?= __('Título') ?><input name="title" required></label>
    <label><?= __('Directorio del podcast') ?><input name="slug" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="mi-podcast"></label>
    <button type="submit"><?= __('Crear podcast') ?></button>
  </form>
</main></div>
</body></html>
