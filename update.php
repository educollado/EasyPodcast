<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/update_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$updateResult = null;

// Procesar la acción de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    csrf_verify();
    // No confiar en URLs enviadas por el navegador: volver a consultar GitHub.
    $latestInfo = getLatestReleaseInfo();
    $updateResult = $latestInfo['error'] !== ''
        ? ['ok' => false, 'message' => $latestInfo['error']]
        : performUpdate($latestInfo['tar_url'], $latestInfo['checksum_url'], __DIR__);
    if ($updateResult['ok']) {
        header('Location: update.php?updated=1');
        exit;
    }
}

$updated = isset($_GET['updated']);
$data    = loadUpdateData(__DIR__);
extract($data); // currentVersion, latestVersion, updateAvailable, fetchError, changelogNotes

$confirmUpdateMessage = __('¿Actualizar EasyPodcast a v%s?\n\nSe descargarán y extraerán los archivos de la aplicación.\nLa base de datos y los archivos de audio/imágenes no se modifican.', $latestVersion);
$updatingLabel        = __('Actualizando…');
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Actualizar EasyPodcast') ?> · EasyPodcast</title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'update'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Actualizar EasyPodcast') ?></h1>
      <p><?= __('Comprueba si hay una nueva versión disponible y aplícala sin perder datos.') ?></p>
      <p>
        <?= __('Se recomienda hacer una copia de seguridad, al menos de la base de datos, antes de actualizar.') ?>
        <a href="backups.php" class="update-footer-link"><?= __('Ir a Copias de seguridad') ?></a>
      </p>

      <?php if ($updated): ?>
        <div class="notice"><?= __('EasyPodcast se ha actualizado correctamente. La base de datos, audios e imágenes no se han modificado.') ?></div>
        <?php if ($changelogNotes !== ''): ?>
          <div class="update-changelog">
            <strong class="update-changelog-title"><?= __('Novedades v%s', $currentVersion) ?></strong>
            <ul class="update-changelog-list">
              <?php foreach (explode("\n", $changelogNotes) as $line): ?>
                <?php $line = trim($line); if ($line === '') { continue; } ?>
                <?php $line = ltrim($line, '- '); ?>
                <?php $line = preg_replace('/`([^`]+)`/', '<code>$1</code>', htmlspecialchars($line, ENT_QUOTES, 'UTF-8')); ?>
                <li><?= $line ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($updateResult !== null && !$updateResult['ok']): ?>
        <div class="error"><?= esc($updateResult['message']) ?></div>
      <?php endif; ?>

      <div class="update-stack">

        <!-- Versiones -->
        <div class="update-version-row">
          <div>
            <span class="update-version-label"><?= __('Instalada') ?></span>
            <strong class="update-version-value"><?= esc($currentVersion) ?></strong>
          </div>
          <?php if ($fetchError === '' && $latestVersion !== ''): ?>
          <div>
            <span class="update-version-label"><?= __('Última disponible') ?></span>
            <strong class="update-version-value <?= $updateAvailable ? 'is-available' : 'is-current' ?>"><?= esc($latestVersion) ?></strong>
          </div>
          <?php endif; ?>
        </div>

        <!-- Estado -->
        <?php if ($fetchError !== ''): ?>
          <div class="error"><?= esc($fetchError) ?></div>

        <?php elseif ($updateAvailable): ?>
          <div class="update-status-warning">
            <?= __('Hay una nueva versión disponible:') ?> <strong>v<?= esc($latestVersion) ?></strong>
          </div>
          <form method="post" action="update.php"
                data-confirm-message="<?= esc($confirmUpdateMessage) ?>"
                data-submit-lock="1"
                data-submit-lock-button=".btn-update"
                data-submit-lock-text="<?= esc($updatingLabel) ?>">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <button class="btn btn-update" type="submit"><?= __('Actualizar a v%s', $latestVersion) ?></button>
          </form>

        <?php else: ?>
          <div class="notice"><?= __('Ya tienes la última versión instalada.') ?></div>

        <?php endif; ?>

      </div>

      <p class="update-footer-note">
        <?= __('La actualización descarga el paquete desde') ?>
        <a href="https://github.com/educollado/EasyPodcast/releases/latest" target="_blank" rel="noopener" class="update-footer-link"><?= __('GitHub Releases') ?></a>
        <?= __('y extrae los archivos sobre la instalación actual.') ?>
        <?= __('La base de datos <code>podcast.sqlite</code>, los audios y las imágenes no se tocan.') ?>
      </p>
    </main>
  </div>
</body>
</html>
