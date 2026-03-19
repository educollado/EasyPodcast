<?php

declare(strict_types=1);

// Panel CRUD de episodios:
// - crear/actualizar/borrar episodios
// - gestionar subidas de audio/imagen
// - regenerar feed.xml automáticamente tras escrituras relevantes

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';
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
extract($data);  // form, isEditing, editingEpisodeId, error, notice
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEditing ? __('Editar Capítulo') : __('Añadir Capítulo') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/jodit.min.css">
</head>
<body>
  <?php $currentAdminPage = 'add'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= $isEditing ? __('Editar Capítulo') : __('Añadir Capítulo') ?></h1>
      <p><?= $isEditing ? __('Edita el capítulo seleccionado.') : __('Completa los datos para insertar un episodio en la tabla <strong>episodes</strong>.') ?></p>

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
            <?= __('GUID (opcional)') ?>
            <input type="text" name="guid" value="<?= esc($form['guid']) ?>" placeholder="Si está vacío se genera automáticamente">
          </label>
          <label>
            <?= __('Estado') ?>
            <select id="status_select" name="status">
              <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>draft</option>
              <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>published</option>
            </select>
          </label>
        </div>

        <div id="pub_date_row" class="grid two" style="margin-top:.8rem;<?= $form['status'] !== 'published' ? 'display:none;' : '' ?>">
          <label>
            <?= __('Fecha de publicación') ?>
            <input id="pub_date" type="datetime-local" name="pub_date" value="<?= esc($form['pub_date']) ?>">
            <span class="help"><?= __('Si se deja vacío se asigna la fecha actual al publicar.') ?></span>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label style="align-self: start">
            <?= __('Título *') ?>
            <input id="title" type="text" name="title" value="<?= esc($form['title']) ?>" required>
          </label>
          <label>
            <?= __('URL del capítulo (opcional)') ?>
            <input id="episode_link" type="url" name="link" value="<?= esc($form['link']) ?>">
            <button id="generate_link_button" class="small-btn" type="button"><?= __('Generar URL') ?></button>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            <?= __('Descripción (sólo texto)') ?>
            <span class="help"><?= __('Máx. 4000 caracteres. Si está rellena, se muestra en portada en lugar del contenido.') ?></span>
            <textarea name="short_description" maxlength="4000"><?= esc($form['short_description']) ?></textarea>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            <?= __('Contenido *') ?>
            <span class="help"><?= __('Máx. 10000 caracteres (incluyendo etiquetas HTML).') ?></span>
            <textarea id="content" name="content" maxlength="10000" required><?= esc($form['content']) ?></textarea>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label>
            <?= __('Audio (URL)') ?>
            <input type="url" name="audio_url" value="<?= esc($form['audio_url']) ?>">
            <span class="help"><?= __('Si subes audio, esta URL se rellena automáticamente con /audios/fichero.') ?></span>
          </label>
          <label style="align-self: start">
            <?= __('O subir audio del capítulo') ?>
            <input id="audio_file" type="file" name="audio_file" accept="audio/*">
          </label>
          <label>
            <?= __('MIME audio *') ?>
            <input id="audio_mime_type" type="text" name="audio_mime_type" value="<?= esc($form['audio_mime_type']) ?>" placeholder="audio/mpeg">
          </label>
          <label>
            <?= __('Tamaño audio en bytes *') ?>
            <input id="audio_size_bytes" type="number" name="audio_size_bytes" min="1" step="1" value="<?= esc($form['audio_size_bytes']) ?>">
          </label>
          <label>
            <?= __('Duración (HH:MM:SS)') ?>
            <input id="duration" type="text" name="duration" value="<?= esc($form['duration']) ?>" placeholder="00:42:10">
          </label>
          <label>
            <?= __('Explícito') ?>
            <select name="explicit">
              <option value="" <?= $form['explicit'] === '' ? 'selected' : '' ?>><?= __('Heredar del podcast') ?></option>
              <option value="0" <?= $form['explicit'] === '0' ? 'selected' : '' ?>>No</option>
              <option value="1" <?= $form['explicit'] === '1' ? 'selected' : '' ?>>Sí</option>
            </select>
          </label>
          <label>
            <?= __('Temporada') ?>
            <input type="number" name="season_number" min="0" step="1" value="<?= esc($form['season_number']) ?>">
          </label>
          <label>
            <?= __('Número de episodio') ?>
            <input type="number" name="episode_number" min="0" step="1" value="<?= esc($form['episode_number']) ?>">
          </label>
          <label>
            <?= __('Imagen del capítulo (URL)') ?>
            <input type="url" name="image_url" value="<?= esc($form['image_url']) ?>">
            <span class="help"><?= __('Si subes imagen, esta URL se rellena automáticamente con /images/fichero.') ?></span>
          </label>
          <label style="align-self: start">
            <?= __('O subir imagen del capítulo') ?>
            <input type="file" name="image_file" accept="image/*">
          </label>
          <label style="align-self: start">
            <?= __('Tipo de episodio') ?>
            <select name="episode_type">
              <option value="" <?= $form['episode_type'] === '' ? 'selected' : '' ?>><?= __('Sin definir') ?></option>
              <option value="full" <?= $form['episode_type'] === 'full' ? 'selected' : '' ?>>full</option>
              <option value="trailer" <?= $form['episode_type'] === 'trailer' ? 'selected' : '' ?>>trailer</option>
              <option value="bonus" <?= $form['episode_type'] === 'bonus' ? 'selected' : '' ?>>bonus</option>
            </select>
          </label>
          <label>
            <?= __('Autor') ?>
            <input type="text" name="author" value="<?= esc($form['author']) ?>">
          </label>
        </div>

        <div class="actions">
          <?php if ($isEditing): ?>
            <a class="btn" href="<?= esc(resolveEpisodeHref($form['link'], '', $form['title'])) ?>" target="_blank"><?= __('Vista previa') ?></a>
            <button class="btn" type="submit" name="rewrite_audio_metadata" value="1"><?= __('Actualizar metadatos del MP3 actual') ?></button>
          <?php endif; ?>
          <button class="btn" type="submit"><?= $isEditing ? __('Actualizar capítulo') : __('Guardar capítulo') ?></button>
        </div>
      </form>
    </main>
  </div>
  <script src="/assets/js/jodit.min.js"></script>
  <script>
    (function () {
      var descArea = document.getElementById('content');
      if (descArea) {
        Jodit.make(descArea, {
          language: 'es',
          toolbar: true,
          buttons: 'paragraph,fontsize,|,bold,italic,underline,strikethrough,superscript,subscript,|,copyformat,eraser,clean,|,ul,ol,|,indent,outdent,|,left,center,right,justify,|,link,image,video,table,hr,|,undo,redo,|,find,preview,fullsize,source',
          toolbarAdaptive: false,
          height: 300,
          enter: 'p',
          cleanHTML: { fillEmptyParagraph: false }
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
        if (!titleInput || !linkInput) {
          return;
        }
        var title = (titleInput.value || '').trim();
        if (!title) {
          return;
        }
        // pub_date ya no existe en el formulario; el servidor la asigna automáticamente.
        // Para previsualizar la URL usamos la fecha actual como aproximación.
        var date = new Date();
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
  <script>
    // Muestra el campo de fecha de publicación solo cuando el estado es "published".
    (function () {
      var statusSelect = document.getElementById('status_select');
      var pubDateRow   = document.getElementById('pub_date_row');
      if (!statusSelect || !pubDateRow) { return; }
      statusSelect.addEventListener('change', function () {
        pubDateRow.style.display = statusSelect.value === 'published' ? '' : 'none';
      });
    }());
  </script>
</body>
</html>
