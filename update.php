<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/update_handler.php';

session_start();
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
    $tarUrl       = (string) ($_POST['tar_url'] ?? '');
    $updateResult = performUpdate($tarUrl, __DIR__);
    if ($updateResult['ok']) {
        header('Location: update.php?updated=1');
        exit;
    }
}

$updated = isset($_GET['updated']);
$data    = loadUpdateData();
extract($data); // currentVersion, latestVersion, tarUrl, updateAvailable, fetchError
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Actualizar · EasyPodcast</title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
</head>
<body>
  <?php $currentAdminPage = 'update'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Actualizar EasyPodcast') ?></h1>
      <p><?= __('Comprueba si hay una nueva versión disponible y aplícala sin perder datos.') ?></p>

      <?php if ($updated): ?>
        <div class="notice"><?= __('EasyPodcast se ha actualizado correctamente. La base de datos, audios e imágenes no se han modificado.') ?></div>
      <?php endif; ?>

      <?php if ($updateResult !== null && !$updateResult['ok']): ?>
        <div class="error"><?= esc($updateResult['message']) ?></div>
      <?php endif; ?>

      <div style="display: grid; gap: 1rem; margin-top: 1rem;">

        <!-- Versiones -->
        <div style="display: flex; gap: 2.5rem; flex-wrap: wrap;">
          <div>
            <span style="color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: .25rem;"><?= __('Instalada') ?></span>
            <strong style="font-size: 1.5rem; font-family: var(--font-display);"><?= esc($currentVersion) ?></strong>
          </div>
          <?php if ($fetchError === '' && $latestVersion !== ''): ?>
          <div>
            <span style="color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: .25rem;"><?= __('Última disponible') ?></span>
            <strong style="font-size: 1.5rem; font-family: var(--font-display); color: <?= $updateAvailable ? 'var(--accent)' : 'var(--ok)' ?>;"><?= esc($latestVersion) ?></strong>
          </div>
          <?php endif; ?>
        </div>

        <!-- Estado -->
        <?php if ($fetchError !== ''): ?>
          <div class="error"><?= esc($fetchError) ?></div>

        <?php elseif ($updateAvailable): ?>
          <div style="background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12; padding: .75rem 1rem; border-radius: 8px; font-size: .91rem;">
            Hay una nueva versión disponible: <strong>v<?= esc($latestVersion) ?></strong>
          </div>
          <form method="post" action="update.php"
                onsubmit="if (!confirm('¿Actualizar EasyPodcast a v<?= esc($latestVersion) ?>?\n\nSe descargarán y extraerán los archivos de la aplicación.\nLa base de datos y los archivos de audio/imágenes no se modifican.')) return false; this.querySelector('.btn-update').textContent = 'Actualizando…'; this.querySelector('.btn-update').disabled = true;">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="tar_url" value="<?= esc($tarUrl) ?>">
            <button class="btn btn-update" type="submit">Actualizar a v<?= esc($latestVersion) ?></button>
          </form>

        <?php else: ?>
          <div class="notice"><?= __('Ya tienes la última versión instalada.') ?></div>

        <?php endif; ?>

      </div>

      <p style="margin-top: 1.5rem; font-size: .83rem; border-top: 1px solid var(--border); padding-top: 1rem;">
        La actualización descarga el paquete desde
        <a href="https://github.com/educollado/EasyPodcast/releases/latest" target="_blank" rel="noopener" style="color: var(--accent);">GitHub Releases</a>
        y extrae los archivos sobre la instalación actual.
        La base de datos <code>podcast.sqlite</code>, los audios y las imágenes no se tocan.
      </p>
    </main>
  </div>
</body>
</html>
