<?php

declare(strict_types=1);

// Panel CRUD de episodios:
// - crear/actualizar/borrar episodios
// - gestionar subidas de audio/imagen
// - regenerar feed.xml automáticamente tras escrituras relevantes

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/add_episode_query.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadAddEpisodeData($dbPath);
extract($data);  // form, isEditing, editingEpisodeId, error, notice, id3Notice
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEditing ? 'Editar Capítulo' : 'Añadir Capítulo' ?></title>
  <link rel="stylesheet" href="/assets/css/episodes_management.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">
</head>
<body>
  <div class="container">
    <main class="card">
      <h1><?= $isEditing ? 'Editar Capítulo' : 'Añadir Capítulo' ?></h1>
      <p><?= $isEditing ? 'Edita el capítulo seleccionado.' : 'Completa los datos para insertar un episodio en la tabla <strong>episodes</strong>.' ?></p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="add_episode.php<?= $isEditing && $editingEpisodeId !== null ? '?episode_id=' . (int) $editingEpisodeId : '' ?>" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <?php if ($isEditing && $editingEpisodeId !== null): ?>
          <input type="hidden" name="episode_id" value="<?= (int) $editingEpisodeId ?>">
        <?php endif; ?>
        <div class="grid two">
          <label>
            GUID (opcional)
            <input type="text" name="guid" value="<?= esc($form['guid']) ?>" placeholder="Si está vacío se genera automáticamente">
          </label>
          <label>
            Fecha de publicación *
            <input id="pub_date" type="datetime-local" name="pub_date" value="<?= esc($form['pub_date']) ?>" required>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label>
            Título *
            <input id="title" type="text" name="title" value="<?= esc($form['title']) ?>" required>
          </label>
          <label>
            URL del capítulo (opcional)
            <input id="episode_link" type="url" name="link" value="<?= esc($form['link']) ?>">
            <button id="generate_link_button" class="small-btn" type="button">Generar URL</button>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            Descripción * <span style="font-weight:400;color:#5f6b73;font-size:.85rem;">(admite Markdown: **negrita**, *cursiva*, listas, enlaces)</span>
            <textarea id="description" name="description" required><?= esc($form['description']) ?></textarea>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label>
            Audio (URL)
            <input type="url" name="audio_url" value="<?= esc($form['audio_url']) ?>">
            <span class="help">Si subes audio, esta URL se rellena automáticamente con /audios/fichero.</span>
          </label>
          <label>
            O subir audio del capítulo
            <input id="audio_file" type="file" name="audio_file" accept="audio/*">
          </label>
          <label>
            MIME audio *
            <input id="audio_mime_type" type="text" name="audio_mime_type" value="<?= esc($form['audio_mime_type']) ?>" placeholder="audio/mpeg">
          </label>
          <label>
            Tamaño audio en bytes *
            <input id="audio_size_bytes" type="number" name="audio_size_bytes" min="1" step="1" value="<?= esc($form['audio_size_bytes']) ?>">
          </label>
          <label>
            Duración (HH:MM:SS)
            <input id="duration" type="text" name="duration" value="<?= esc($form['duration']) ?>" placeholder="00:42:10">
          </label>
          <label>
            Explícito
            <select name="explicit">
              <option value="" <?= $form['explicit'] === '' ? 'selected' : '' ?>>Heredar del podcast</option>
              <option value="0" <?= $form['explicit'] === '0' ? 'selected' : '' ?>>No</option>
              <option value="1" <?= $form['explicit'] === '1' ? 'selected' : '' ?>>Sí</option>
            </select>
          </label>
          <label>
            Temporada
            <input type="number" name="season_number" min="0" step="1" value="<?= esc($form['season_number']) ?>">
          </label>
          <label>
            Número de episodio
            <input type="number" name="episode_number" min="0" step="1" value="<?= esc($form['episode_number']) ?>">
          </label>
          <label>
            Tipo de episodio
            <select name="episode_type">
              <option value="" <?= $form['episode_type'] === '' ? 'selected' : '' ?>>Sin definir</option>
              <option value="full" <?= $form['episode_type'] === 'full' ? 'selected' : '' ?>>full</option>
              <option value="trailer" <?= $form['episode_type'] === 'trailer' ? 'selected' : '' ?>>trailer</option>
              <option value="bonus" <?= $form['episode_type'] === 'bonus' ? 'selected' : '' ?>>bonus</option>
            </select>
          </label>
          <label>
            Imagen del capítulo (URL)
            <input type="url" name="image_url" value="<?= esc($form['image_url']) ?>">
            <span class="help">Si subes imagen, esta URL se rellena automáticamente con /images/fichero.</span>
          </label>
          <label>
            O subir imagen del capítulo
            <input type="file" name="image_file" accept="image/*">
          </label>
          <label>
            Autor
            <input type="text" name="author" value="<?= esc($form['author']) ?>">
          </label>
          <label>
            Estado
            <select name="status">
              <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>draft</option>
              <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>published</option>
            </select>
          </label>
        </div>

        <div class="actions">
          <a class="btn back" href="episodes_management.php">Volver a capítulos</a>
          <?php if ($isEditing): ?>
            <button class="btn" type="submit" name="rewrite_audio_metadata" value="1">Actualizar metadatos del MP3 actual</button>
          <?php endif; ?>
          <button class="btn" type="submit"><?= $isEditing ? 'Actualizar capítulo' : 'Guardar capítulo' ?></button>
        </div>
      </form>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>
  <script>
    (function () {
      var descArea = document.getElementById('description');
      if (descArea) {
        new EasyMDE({
          element: descArea,
          toolbar: [
            'bold', 'italic', 'heading', '|',
            'unordered-list', 'ordered-list', '|',
            'link', '|',
            'preview'
          ],
          spellChecker: false,
          forceSync: true,
          status: false,
          minHeight: '140px'
        });
      }
    })();
  </script>
  <script>
    (function () {
      var audioInput = document.getElementById('audio_file');
      var sizeInput = document.getElementById('audio_size_bytes');
      var durationInput = document.getElementById('duration');
      var mimeInput = document.getElementById('audio_mime_type');
      var titleInput = document.getElementById('title');
      var pubDateInput = document.getElementById('pub_date');
      var linkInput = document.getElementById('episode_link');
      var generateLinkButton = document.getElementById('generate_link_button');

      if (!audioInput || !sizeInput || !durationInput) {
        return;
      }

      function formatDuration(totalSeconds) {
        if (!Number.isFinite(totalSeconds) || totalSeconds < 0) {
          return '';
        }

        var s = Math.floor(totalSeconds);
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
      }

      function slugify(value) {
        var normalized = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return normalized
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '') || 'capitulo';
      }

      function buildEpisodeLink() {
        if (!titleInput || !pubDateInput || !linkInput) {
          return;
        }
        var title = (titleInput.value || '').trim();
        if (!title) {
          return;
        }
        var rawDate = (pubDateInput.value || '').trim();
        var date = rawDate ? new Date(rawDate) : new Date();
        if (!Number.isFinite(date.getTime())) {
          date = new Date();
        }
        var year = String(date.getFullYear());
        var month = String(date.getMonth() + 1).padStart(2, '0');
        linkInput.value = window.location.origin + '/' + year + '/' + month + '/' + slugify(title);
      }

      audioInput.addEventListener('change', function () {
        var file = audioInput.files && audioInput.files[0] ? audioInput.files[0] : null;
        if (!file) {
          return;
        }

        sizeInput.value = String(file.size || '');
        if (mimeInput && file.type) {
          mimeInput.value = file.type;
        }

        var objectUrl = URL.createObjectURL(file);
        var probe = new Audio();
        probe.preload = 'metadata';
        probe.src = objectUrl;

        probe.addEventListener('loadedmetadata', function () {
          durationInput.value = formatDuration(probe.duration);
          URL.revokeObjectURL(objectUrl);
        });

        probe.addEventListener('error', function () {
          URL.revokeObjectURL(objectUrl);
        });
      });

      if (generateLinkButton) {
        generateLinkButton.addEventListener('click', buildEpisodeLink);
      }

    })();
  </script>
</body>
</html>
