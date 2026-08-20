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
$summaryTitleValue = $settings['summary_title'] !== '' ? $settings['summary_title'] : __('Todos nuestros podcasts, en un solo lugar.');
$summarySubtitleValue = $settings['summary_subtitle'] !== '' ? $settings['summary_subtitle'] : __('Descubre todos los podcasts disponibles y sus feeds RSS.');
$primaryPodcastTitle = trim((string) ($primary_podcast['title'] ?? ''));
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Multipodcast') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/podcast-management.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/podcast-management.css') ?>">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
<?php $currentAdminPage = 'multipodcast'; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-wrap"><main class="card">
  <h1><?= __('Multipodcast') ?></h1>
  <?php if ($error !== ''): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="notice"><?= esc($notice) ?></div><?php endif; ?>
  <?php if ($backup_file !== ''): ?><p><a class="button" href="multipodcast.php?download_backup=<?= esc(rawurlencode($backup_file)) ?>"><?= __('Descargar copia de seguridad') ?></a></p><?php endif; ?>

  <h2><?= __('Configuración Multipodcast') ?></h2>
  <form method="post" action="multipodcast.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_settings">
    <label class="inline-checkbox multipodcast-toggle">
      <input id="multipodcast_enabled" type="checkbox" name="multipodcast_enabled" value="1" <?= $settings['multipodcast_enabled'] === 1 ? 'checked' : '' ?>>
      <span><?= __('Activar Multipodcast') ?></span>
    </label>
    <div class="multipodcast-warning" role="status" aria-live="polite">
      <p data-multipodcast-enabled-warning <?= $settings['multipodcast_enabled'] !== 1 ? 'hidden' : '' ?>><?= __('Al activarlo, cada podcast usará su propio directorio y cambiarán sus URLs públicas. La portada principal mostrará el resumen o el podcast elegido; las URLs antiguas de episodios solo se redirigirán si eliges un podcast para la portada.') ?></p>
      <p data-multipodcast-disabled-warning <?= $settings['multipodcast_enabled'] === 1 ? 'hidden' : '' ?>><?= esc(__('Al desactivar Multipodcast, solo se mostrará el podcast principal «%s». Los demás podcasts y sus datos se conservarán, pero no serán accesibles públicamente hasta volver a activar Multipodcast.', $primaryPodcastTitle)) ?></p>
    </div>
    <label for="homepage_podcast_id"><?= __('Contenido de la portada principal') ?></label>
    <select id="homepage_podcast_id" name="homepage_podcast_id">
      <option value=""><?= __('Resumen de todos los podcasts') ?></option>
      <?php foreach ($podcasts as $podcast): ?>
        <option value="<?= (int) $podcast['id'] ?>" <?= $settings['homepage_podcast_id'] === (int) $podcast['id'] ? 'selected' : '' ?>><?= esc((string) $podcast['title']) ?></option>
      <?php endforeach; ?>
    </select>

    <section
      class="summary-hero-settings"
      data-summary-hero-settings
      <?= $settings['homepage_podcast_id'] !== null ? 'hidden' : '' ?>
      aria-label="<?= esc(__('Imagen del hero')) ?>"
    >
      <article class="podcast-image-card">
        <h2><?= __('Imagen del hero') ?></h2>
        <p class="summary-hero-help"><?= __('Esta imagen se mostrará únicamente cuando la portada principal sea el resumen de todos los podcasts.') ?></p>
        <div class="podcast-image-preview podcast-image-preview-hero">
          <img
            id="summary-hero-image-preview"
            <?php if ($settings['summary_hero_image_url'] !== ''): ?>src="<?= esc($settings['summary_hero_image_url']) ?>"<?php endif; ?>
            alt="<?= esc(__('Vista previa de la imagen del hero')) ?>"
            <?= $settings['summary_hero_image_url'] === '' ? 'hidden' : '' ?>
          >
          <span id="summary-hero-image-placeholder" <?= $settings['summary_hero_image_url'] !== '' ? 'hidden' : '' ?>>
            <?= __('Sin imagen') ?>
          </span>
        </div>
        <label>
          <?= __('Imagen del hero (URL)') ?>
          <input
            type="url"
            name="summary_hero_image_url"
            value="<?= esc($settings['summary_hero_image_url']) ?>"
            data-image-preview="summary-hero-image-preview"
          >
          <small><?= __('Déjala vacía para mantener la cabecera actual sin hero.') ?></small>
        </label>
        <label>
          <?= __('O subir imagen para el hero') ?>
          <input
            type="file"
            name="summary_hero_image_file"
            accept="image/jpeg,image/png,image/gif,image/webp"
            data-image-preview="summary-hero-image-preview"
          >
          <small><?= __('La imagen subida se recorta y optimiza automáticamente para la cabecera.') ?></small>
          <small><?= __('La imagen se recortará para cubrir la cabecera sin cambiar su tamaño.') ?></small>
        </label>
      </article>
      <div class="summary-content-settings">
        <label>
          <?= __('Tema del resumen') ?>
          <select name="summary_theme">
            <?php foreach (ADMIN_THEMES as $themeSlug => $themeLabel): ?>
              <option value="<?= esc($themeSlug) ?>" <?= $settings['summary_theme'] === $themeSlug ? 'selected' : '' ?>><?= esc($themeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="grid two">
          <label>
            <?= __('Título del resumen') ?>
            <input type="text" name="summary_title" value="<?= esc($summaryTitleValue) ?>">
          </label>
          <label>
            <?= __('Subtítulo del resumen') ?>
            <input type="text" name="summary_subtitle" value="<?= esc($summarySubtitleValue) ?>">
          </label>
        </div>
      </div>
    </section>
    <button type="submit"><?= __('Guardar configuración') ?></button>
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
          <img
            class="podcast-admin-cover"
            src="<?= esc($podcastImageSources['src']) ?>"
            <?php if ($podcastImageSources['srcset'] !== ''): ?>srcset="<?= esc($podcastImageSources['srcset']) ?>" sizes="80px"<?php endif; ?>
            width="80"
            height="80"
            alt="<?= esc(__('Portada del podcast')) ?>"
          >
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
          <form method="post" action="multipodcast.php">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_primary">
            <input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
            <button class="btn back" type="submit"><?= __('Marcar como principal') ?></button>
          </form>
        <?php endif; ?>
      </div>
      <form class="podcast-admin-form" method="post" action="multipodcast.php">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="action" value="rename_slug"><input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
        <label><?= __('Directorio del podcast') ?><input name="slug" value="<?= esc((string) ($podcast['slug'] ?? '')) ?>" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*"></label>
        <button class="btn" type="submit"><?= __('Cambiar directorio') ?></button>
      </form>
      <?php if (count($podcasts) > 1): ?>
      <form class="podcast-admin-form podcast-admin-delete" method="post" action="multipodcast.php" data-confirm-message="<?= esc(__('Se creará una copia ZIP y se borrarán definitivamente el podcast, sus capítulos, estadísticas y medios. ¿Continuar?')) ?>">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="podcast_id" value="<?= (int) $podcast['id'] ?>">
        <label><?= __('Escribe el título para confirmar') ?><input name="confirm_title" required autocomplete="off"></label>
        <button class="btn danger" type="submit"><?= __('Crear backup y borrar podcast') ?></button>
      </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>

  <h2><?= __('Crear un podcast nuevo') ?></h2>
  <form method="post" action="multipodcast.php">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label><?= __('Título') ?><input name="title" required></label>
    <label><?= __('Directorio del podcast') ?><input name="slug" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="mi-podcast"></label>
    <button type="submit"><?= __('Crear podcast') ?></button>
  </form>
</main></div>
<script src="/assets/js/podcast_management.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/podcast_management.js') ?>"></script>
<script src="/assets/js/multipodcast.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/multipodcast.js') ?>"></script>
</body></html>
