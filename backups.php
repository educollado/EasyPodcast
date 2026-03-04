<?php

declare(strict_types=1);

// Herramientas de copias de seguridad:
// - exportar la base de datos actual
// - importar una base de datos con backup previo

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/backup_handler.php';

// El acceso a esta pantalla exige sesión de administrador activa.
session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
// Fuerza el dominio canónico para evitar acciones de administración desde host alternativo.
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadBackupsData($dbPath, __DIR__);
extract($data); // error, notice, imagesExport, audiosExport
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Copias de seguridad</title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
</head>
<body>
  <?php $currentAdminPage = 'backups'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
  <main class="card">
    <h1>Copias de seguridad</h1>
    <p>Gestiona por separado la base de datos y los ficheros multimedia.</p>

    <?php if ($error !== ''): ?>
      <div class="error"><?= esc($error) ?></div>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice"><?= esc($notice) ?></div>
    <?php endif; ?>

    <div class="backup-groups">
      <section class="tool-box" aria-label="Bloque base de datos">
        <h2>Base de Datos</h2>
        <p>Exporta o importa el archivo SQLite del podcast.</p>
        <div class="db-tools">
          <a class="btn db-export" href="backups.php?action=export_db">Exportar base de datos</a>
          <form class="db-import-form" method="post" action="backups.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="db_action" value="import_db">
            <label for="db_file">Importar base de datos</label>
            <input id="db_file" type="file" name="db_file" accept=".sqlite,.db" required>
            <button class="btn db-import" type="submit">Importar base de datos</button>
          </form>
        </div>
      </section>

      <section class="tool-box" aria-label="Bloque ficheros">
        <h2>Ficheros</h2>
        <p>Exporta por separado <code>images/</code> y <code>audios/</code> en partes ZIP de hasta 127 MB.</p>
        <div class="db-tools">
          <div>
            <strong>Exportar imágenes</strong>
            <?php if ($imagesExport['error'] !== ''): ?>
              <p class="error"><?= esc((string) $imagesExport['error']) ?></p>
            <?php elseif ($imagesExport['totalFiles'] === 0): ?>
              <p>No hay ficheros en <code>images/</code>.</p>
            <?php else: ?>
              <p>
                Total: <?= (int) $imagesExport['totalFiles'] ?> ficheros.
                Exportables en ZIP: <?= (int) $imagesExport['exportedFiles'] ?> en <?= count($imagesExport['parts']) ?> parte(s).
              </p>
              <?php foreach ($imagesExport['parts'] as $idx => $part): ?>
                <a class="btn files-export" href="backups.php?action=export_media_part&amp;type=images&amp;part=<?= $idx + 1 ?>">
                  Descargar imágenes parte <?= $idx + 1 ?> (<?= number_format($part['bytes'] / 1048576, 2) ?> MB)
                </a>
              <?php endforeach; ?>
              <?php if (count($imagesExport['skipped']) > 0): ?>
                <p class="error">
                  Algunos ficheros de <code>images/</code> superan 127 MB y no se incluyen en ZIP.
                  Descargalos manualmente:
                </p>
                <?php foreach ($imagesExport['skipped'] as $skipped): ?>
                  <p>
                    <a href="<?= esc(mediaPathToHref((string) $skipped['zip'])) ?>" target="_blank" rel="noopener">
                      <?= esc((string) $skipped['zip']) ?>
                    </a>
                    (<?= number_format(((int) $skipped['size']) / 1048576, 2) ?> MB)
                  </p>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <div>
            <strong>Exportar audios</strong>
            <?php if ($audiosExport['error'] !== ''): ?>
              <p class="error"><?= esc((string) $audiosExport['error']) ?></p>
            <?php elseif ($audiosExport['totalFiles'] === 0): ?>
              <p>No hay ficheros en <code>audios/</code>.</p>
            <?php else: ?>
              <p>
                Total: <?= (int) $audiosExport['totalFiles'] ?> ficheros.
                Exportables en ZIP: <?= (int) $audiosExport['exportedFiles'] ?> en <?= count($audiosExport['parts']) ?> parte(s)
                y <?= count($audiosExport['skipped']) ?> audios no exportables en ZIP.
              </p>
              <?php foreach ($audiosExport['parts'] as $idx => $part): ?>
                <a class="btn files-export" href="backups.php?action=export_media_part&amp;type=audios&amp;part=<?= $idx + 1 ?>">
                  Descargar audios parte <?= $idx + 1 ?> (<?= number_format($part['bytes'] / 1048576, 2) ?> MB)
                </a>
              <?php endforeach; ?>
              <?php if (count($audiosExport['skipped']) > 0): ?>
                <p class="error">
                  Algunos ficheros de <code>audios/</code> superan 127 MB y no se incluyen en ZIP.
                  Descargalos manualmente:
                </p>
                <?php foreach ($audiosExport['skipped'] as $skipped): ?>
                  <p>
                    <a href="<?= esc(mediaPathToHref((string) $skipped['zip'])) ?>" target="_blank" rel="noopener">
                      <?= esc((string) $skipped['zip']) ?>
                    </a>
                    (<?= number_format(((int) $skipped['size']) / 1048576, 2) ?> MB)
                  </p>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <form class="db-import-form" method="post" action="backups.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="files_action" value="import_files_zip">
            <label for="files_zip">Importar ficheros (uno o varios ZIP o audios)</label>
            <input id="files_zip" type="file" name="files_zip[]" accept=".zip" multiple>
            <label for="audio_files">Audios MP3 sueltos (opcional)</label>
            <input id="audio_files" type="file" name="audio_files[]" accept=".mp3,audio/mpeg" multiple>
            <button class="btn files-import" type="submit">Importar ZIP(s) y/o audios</button>
          </form>
        </div>
      </section>
    </div>

  </main>
  </div>
</body>
</html>
