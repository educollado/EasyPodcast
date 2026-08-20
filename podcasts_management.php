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
requireGlobalAdminAccess();
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
$podcastsTheme = isset(ADMIN_THEMES[$settings['summary_theme']]) ? $settings['summary_theme'] : 'easypodcast';
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc($podcastsTheme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Podcasts') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/podcast-management.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/podcast-management.css') ?>">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
<?php $currentAdminPage = 'podcasts'; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-wrap"><main class="card">
  <h1><?= __('Podcasts') ?></h1>
  <?php if ($error !== ''): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="notice"><?= esc($notice) ?></div><?php endif; ?>
  <?php if ($backup_file !== ''): ?><p><a class="button" href="podcasts_management.php?download_backup=<?= esc(rawurlencode($backup_file)) ?>"><?= __('Descargar copia de seguridad') ?></a></p><?php endif; ?>

  <h2><?= __('Crear un podcast nuevo') ?></h2>
  <form method="post" action="podcasts_management.php">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label><?= __('Título') ?><input name="title" required></label>
    <label><?= __('Directorio del podcast') ?><input name="slug" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="mi-podcast"></label>
    <button type="submit"><?= __('Crear podcast') ?></button>
  </form>

  <h2><?= __('Podcasts disponibles') ?></h2>
  <div class="admin-cards podcast-admin-list">
  <?php foreach ($podcasts as $podcast): ?>
    <?php
      $isPrimaryPodcast = (int) $podcast['id'] === (int) ($primary_podcast['id'] ?? 0);
      $podcastImage = trim((string) ($podcast['image_url'] ?? ''));
      $podcastImageSources = $podcastImage !== ''
          ? buildResponsiveSquareImageSources($podcastImage, [80, 144])
          : ['src' => '', 'srcset' => ''];
    ?>
    <section class="admin-card podcast-admin-card<?= $isPrimaryPodcast ? ' is-primary' : '' ?>">
      <div class="podcast-admin-card-header">
        <?php if ($podcastImageSources['src'] !== ''): ?>
          <img class="podcast-admin-cover" src="<?= esc($podcastImageSources['src']) ?>" <?php if ($podcastImageSources['srcset'] !== ''): ?>srcset="<?= esc($podcastImageSources['srcset']) ?>" sizes="80px"<?php endif; ?> width="80" height="80" alt="<?= esc(__('Portada del podcast')) ?>">
        <?php else: ?>
          <div class="podcast-admin-cover-placeholder" aria-hidden="true">🎙️</div>
        <?php endif; ?>
        <div class="podcast-admin-card-title">
          <h3><?= esc((string) $podcast['title']) ?></h3>
          <?php if ($isPrimaryPodcast): ?><span class="podcast-primary-badge"><?= __('Podcast principal') ?></span><?php endif; ?>
        </div>
      </div>
      <div class="podcast-admin-meta">
        <p><?= __('Directorio:') ?> <code>/<?= esc((string) ($podcast['slug'] ?? '')) ?>/</code></p>
        <p><?= __('%d capítulos', (int) $podcast['episode_count']) ?></p>
      </div>
      <div class="podcast-admin-actions">
        <a class="btn" href="admin.php?podcast=<?= esc(rawurlencode((string) $podcast['slug'])) ?>&amp;manage=1"><?= __('Administrar podcast') ?></a>
        <?php if (!$isPrimaryPodcast): ?>
          <form method="post" action="podcasts_management.php">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_primary">
            <input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
            <button class="btn back" type="submit"><?= __('Marcar como principal') ?></button>
          </form>
        <?php endif; ?>
      </div>
      <form class="podcast-admin-form" method="post" action="podcasts_management.php">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="action" value="rename_slug"><input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
        <label><?= __('Directorio del podcast') ?><input name="slug" value="<?= esc((string) ($podcast['slug'] ?? '')) ?>" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*"></label>
        <button class="btn" type="submit"><?= __('Cambiar directorio') ?></button>
      </form>
      <?php if (count($podcasts) > 1): ?>
      <form class="podcast-admin-form podcast-admin-delete" method="post" action="podcasts_management.php" data-confirm-message="<?= esc(__('Se creará una copia ZIP y se borrarán definitivamente el podcast, sus capítulos, estadísticas y medios. ¿Continuar?')) ?>">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
        <label><?= __('Escribe el título para confirmar') ?><input name="confirm_title" required autocomplete="off"></label>
        <button class="btn danger" type="submit"><?= __('Crear backup y borrar podcast') ?></button>
      </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
</main></div>
</body></html>
