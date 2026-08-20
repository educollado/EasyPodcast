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

if (isset($_GET['download_backup']) && isset($_SESSION['podcast_backup_files']) && is_array($_SESSION['podcast_backup_files'])) {
    $requestedBackup = basename((string) $_GET['download_backup']);
    $allowedBackups = array_map('basename', $_SESSION['podcast_backup_files']);
    if (in_array($requestedBackup, $allowedBackups, true)) {
        $backupPath = __DIR__ . '/backups/' . $requestedBackup;
        if (is_file($backupPath)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $requestedBackup . '"');
            header('Content-Length: ' . filesize($backupPath));
            readfile($backupPath);
            exit;
        }
    }
}

$data = loadPodcastsManagementData($dbPath, __DIR__);
extract($data);
$summaryTitleValue = $settings['summary_title'] !== '' ? $settings['summary_title'] : __('Todos nuestros podcasts, en un solo lugar.');
$summarySubtitleValue = $settings['summary_subtitle'] !== '' ? $settings['summary_subtitle'] : __('Descubre todos los podcasts disponibles y sus feeds RSS.');
$primaryPodcastTitle = trim((string) ($primary_podcast['title'] ?? ''));
$primaryPodcastSlug = trim((string) ($primary_podcast['slug'] ?? ''));
$conversionSlug = $primaryPodcastSlug !== '' ? $primaryPodcastSlug : normalizePodcastSlug($primaryPodcastTitle);
$secondaryPodcastCount = max(0, count($podcasts) - 1);
$multipodcastTheme = isset(ADMIN_THEMES[$settings['summary_theme']]) ? $settings['summary_theme'] : 'easypodcast';
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc($multipodcastTheme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Multipodcast') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/podcast-management.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/podcast-management.css') ?>">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
<?php $currentAdminPage = 'multipodcast_settings'; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-wrap"><main class="card">
  <h1><?= __('Multipodcast') ?></h1>
  <?php if ($error !== ''): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="notice"><?= esc($notice) ?></div><?php endif; ?>
  <?php if ($backup_files !== []): ?>
    <div class="notice">
      <p><?= __('Se ha creado una copia de seguridad de cada podcast secundario eliminado:') ?></p>
      <?php foreach ($backup_files as $deletedBackup): ?>
        <p><a class="button" href="multipodcast_management.php?download_backup=<?= esc(rawurlencode($deletedBackup)) ?>"><?= esc(__('Descargar %s', $deletedBackup)) ?></a></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2><?= __('Configuración Multipodcast') ?></h2>
  <form method="post" action="multipodcast_management.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_settings">
    <label class="inline-checkbox multipodcast-toggle">
      <input id="multipodcast_enabled" type="checkbox" name="multipodcast_enabled" value="1" data-initial-enabled="<?= $settings['multipodcast_enabled'] === 1 ? '1' : '0' ?>" <?= $settings['multipodcast_enabled'] === 1 ? 'checked' : '' ?>>
      <span><?= __('Activar Multipodcast') ?></span>
    </label>
    <div class="multipodcast-warning" data-multipodcast-warning role="status" aria-live="polite" hidden>
      <p data-multipodcast-enabled-warning hidden><?= __('Al activarlo, cada podcast tendrá su propio directorio para páginas, episodios y feeds. Las URLs de imágenes y audios no cambiarán.') ?></p>
      <p data-multipodcast-disabled-warning hidden><?= esc(__('Al desactivar Multipodcast, «%s» volverá a ser el único podcast. Se crearán copias de seguridad y se borrarán definitivamente los otros %d podcasts junto con sus datos y medios.', $primaryPodcastTitle, $secondaryPodcastCount)) ?></p>
    </div>
    <section data-multipodcast-enable-settings hidden>
      <h3><?= __('Convertir el podcast actual a Multipodcast') ?></h3>
      <p><?= esc(__('«%s» será el podcast principal. Sus imágenes, audios y URLs multimedia se conservarán sin cambios.', $primaryPodcastTitle)) ?></p>
      <label for="conversion_slug"><?= __('Directorio del podcast principal') ?></label>
      <div class="input-prefix"><span>/</span><input id="conversion_slug" name="conversion_slug" value="<?= esc($conversionSlug) ?>" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" autocomplete="off"><span>/</span></div>
      <small><?= __('Debe estar libre y solo puede contener letras minúsculas, números y guiones.') ?></small>
    </section>
    <section data-multipodcast-disable-settings hidden>
      <h3><?= __('Confirmar la desactivación de Multipodcast') ?></h3>
      <p><?= esc(__('Las imágenes, audios y URLs multimedia de «%s» no cambiarán. Los podcasts secundarios se borrarán después de crear sus copias ZIP.', $primaryPodcastTitle)) ?></p>
      <label class="inline-checkbox">
        <input type="checkbox" name="confirm_disable" value="1" data-disable-confirm-checkbox>
        <span><?= __('Entiendo que los podcasts secundarios se borrarán definitivamente.') ?></span>
      </label>
      <label for="disable_confirm_title"><?= __('Escribe el título del podcast principal para confirmar') ?></label>
      <input id="disable_confirm_title" name="disable_confirm_title" autocomplete="off" data-disable-confirm-title>
    </section>
    <fieldset class="homepage-choice">
      <legend><?= __('¿Qué quieres mostrar en la portada principal?') ?></legend>
      <label class="homepage-choice-option">
        <input type="radio" name="homepage_mode" value="summary" <?= $settings['homepage_podcast_id'] === null ? 'checked' : '' ?>>
        <span>
          <strong><?= __('Resumen de todos los podcasts') ?></strong>
          <small><?= __('Muestra los podcasts publicados ordenados por última actualización.') ?></small>
        </span>
      </label>
      <label class="homepage-choice-option">
        <input type="radio" name="homepage_mode" value="podcast" <?= $settings['homepage_podcast_id'] !== null ? 'checked' : '' ?>>
        <span>
          <strong><?= __('Un único podcast') ?></strong>
          <small><?= __('Muestra la portada del podcast seleccionado.') ?></small>
        </span>
      </label>
      <div data-homepage-podcast-settings <?= $settings['homepage_podcast_id'] === null ? 'hidden' : '' ?>>
        <label for="homepage_podcast_id"><?= __('Podcast que se mostrará') ?></label>
        <select id="homepage_podcast_id" name="homepage_podcast_id">
          <?php foreach ($podcasts as $podcast): ?>
            <option value="<?= (int) $podcast['id'] ?>" <?= $settings['homepage_podcast_id'] === (int) $podcast['id'] ? 'selected' : '' ?>><?= esc((string) $podcast['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </fieldset>

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
          <select id="summary_theme" name="summary_theme" data-summary-theme-selector>
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

</main></div>
<script src="/assets/js/podcast_management.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/podcast_management.js') ?>"></script>
<script src="/assets/js/multipodcast.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/multipodcast.js') ?>"></script>
</body></html>
