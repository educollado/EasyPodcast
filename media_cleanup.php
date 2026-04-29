<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/media_cleanup_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadMediaCleanupData($dbPath, __DIR__);
extract($data); // orphanAudios, orphanImages, error, notice

$totalOrphans    = count($orphanAudios) + count($orphanImages);
$totalAudioBytes = array_sum($orphanAudios);
$totalImageBytes = array_sum($orphanImages);
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Limpiar archivos huérfanos') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'dashboard'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
  <main class="card">
    <h1><?= __('Limpiar archivos huérfanos') ?></h1>
    <p><?= __('Archivos en <code>audios/</code> e <code>images/</code> que no están vinculados a ningún episodio.') ?></p>

    <?php if ($error !== ''): ?>
      <div class="error"><?= esc($error) ?></div>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice"><?= esc($notice) ?></div>
    <?php endif; ?>

    <?php if ($totalOrphans === 0): ?>
      <p class="notice"><?= __('No se encontraron archivos sin usar.') ?></p>
    <?php else: ?>
      <form method="post" action="media_cleanup.php" id="cleanup-form"
            data-empty-selection-message="<?= esc(__('Selecciona al menos un archivo.')) ?>"
            data-confirm-message="<?= esc(__('¿Borrar los archivos seleccionados? Esta acción no se puede deshacer.')) ?>">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">

        <?php if (count($orphanAudios) > 0): ?>
          <h2>
            <?= __('Audios sin usar') ?>
            (<?= count($orphanAudios) ?>,
            <?= esc(mediaCleanupFormatBytes((int) $totalAudioBytes)) ?>)
          </h2>
          <table class="data-table">
            <thead>
              <tr>
                <th class="narrow-col"></th>
                <th><?= __('Archivo') ?></th>
                <th><?= __('Tamaño') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orphanAudios as $filename => $size): ?>
                <tr>
                  <td><input type="checkbox" name="files[]" value="audio:<?= esc($filename) ?>"></td>
                  <td><?= esc($filename) ?></td>
                  <td><?= esc(mediaCleanupFormatBytes($size)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <?php if (count($orphanImages) > 0): ?>
          <h2 class="section-gap-lg">
            <?= __('Imágenes sin usar') ?>
            (<?= count($orphanImages) ?>,
            <?= esc(mediaCleanupFormatBytes((int) $totalImageBytes)) ?>)
          </h2>
          <table class="data-table">
            <thead>
              <tr>
                <th class="narrow-col"></th>
                <th><?= __('Archivo') ?></th>
                <th><?= __('Tamaño') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orphanImages as $filename => $size): ?>
                <tr>
                  <td><input type="checkbox" name="files[]" value="image:<?= esc($filename) ?>"></td>
                  <td><?= esc($filename) ?></td>
                  <td><?= esc(mediaCleanupFormatBytes($size)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <div class="actions section-gap-lg">
          <button type="button" id="btn-select-all" class="btn btn-secondary"
                  data-select-label="<?= esc(__('Seleccionar todo')) ?>"
                  data-deselect-label="<?= esc(__('Deseleccionar todo')) ?>">
            <?= __('Seleccionar todo') ?>
          </button>
          <button type="submit" class="btn">
            <?= __('Borrar seleccionados') ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </main>
  </div>

  <script src="/assets/js/media_cleanup.js"></script>
</body>
</html>
