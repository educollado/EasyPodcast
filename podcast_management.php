<?php

declare(strict_types=1);

// Panel de gestión de metadatos del podcast (una sola fila de canal).

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/podcast_management_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadPodcastManagementData($dbPath);
extract($data);  // form, error, notice
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestión Podcast</title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
</head>
<body>
  <?php $currentAdminPage = 'podcast'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1>Gestión Podcast</h1>
      <p>Completa los metadatos del canal para rellenar la tabla <strong>podcast</strong>.</p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="podcast_management.php" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <div class="grid two">
          <label>
            Título *
            <input type="text" name="title" value="<?= esc($form['title']) ?>" required>
          </label>
          <label>
            URL principal *
            <input type="url" name="link" value="<?= esc($form['link']) ?>" required>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            Descripción *
            <textarea name="description" required><?= esc($form['description']) ?></textarea>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label>
            Idioma
            <input type="text" name="language" value="<?= esc($form['language']) ?>" placeholder="es-ES">
          </label>
          <label>
            Autor (itunes:author)
            <input type="text" name="author" value="<?= esc($form['author']) ?>">
          </label>
          <label>
            Owner name
            <input type="text" name="owner_name" value="<?= esc($form['owner_name']) ?>">
          </label>
          <label>
            Owner email
            <input type="email" name="owner_email" value="<?= esc($form['owner_email']) ?>">
          </label>
          <label>
            Categorías (separadas por coma)
            <input type="text" name="category" value="<?= esc($form['category']) ?>" placeholder="Technology, Education">
          </label>
          <label>
            Explícito
            <select name="explicit">
              <option value="0" <?= $form['explicit'] === '0' ? 'selected' : '' ?>>No</option>
              <option value="1" <?= $form['explicit'] === '1' ? 'selected' : '' ?>>Sí</option>
            </select>
          </label>
          <label>
            Imagen del podcast (URL)
            <input type="url" name="image_url" value="<?= esc($form['image_url']) ?>">
          </label>
          <label>
            O subir imagen del podcast
            <input type="file" name="image_file" accept="image/*">
          </label>
          <label>
            Tipo iTunes
            <select name="itunes_type">
              <option value="episodic" <?= $form['itunes_type'] === 'episodic' ? 'selected' : '' ?>>episodic</option>
              <option value="serial" <?= $form['itunes_type'] === 'serial' ? 'selected' : '' ?>>serial</option>
            </select>
          </label>
          <label>
            Cantidad de elementos del Feed RSS
            <input type="number" min="0" step="1" name="rss_item_limit" value="<?= esc($form['rss_item_limit']) ?>">
            <small>Nota: 0 significa infinitos (sin límite).</small>
          </label>
          <label>
            Cantidad de elementos de la portada
            <input type="number" min="1" step="1" name="home_items_per_page" value="<?= esc($form['home_items_per_page']) ?>">
            <small>Controla cuántos episodios se muestran por página en la portada.</small>
          </label>
          <label class="inline-checkbox">
            <input type="checkbox" name="write_audio_metadata" value="1" <?= $form['write_audio_metadata'] === '1' ? 'checked' : '' ?>>
            <span>Escribir metadatos ID3 en MP3 al subir episodio</span>
            <small>Usa datos del episodio/podcast para título, artista, álbum, fecha, comentario y pista.</small>
          </label>
          <label class="inline-checkbox">
            <input type="checkbox" name="cache_enabled" value="1" <?= $form['cache_enabled'] === '1' ? 'checked' : '' ?>>
            <span>Habilitar caché pública en /cache</span>
            <small>Aplica a portada, episodio, feed y sitemap.</small>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            Copyright
            <input type="text" name="copyright" value="<?= esc($form['copyright']) ?>">
          </label>
        </div>

        <div class="actions">
          <a class="btn back" href="admin.php">Volver al panel</a>
          <form method="post" action="podcast_management.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="cache_action" value="clear_cache">
            <button class="btn back" type="submit">Borrar caché</button>
          </form>
          <button class="btn" type="submit">Guardar podcast</button>
        </div>
      </form>
    </main>
  </div>
</body>
</html>
