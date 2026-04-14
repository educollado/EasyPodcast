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
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEditing ? __('Editar Capítulo') : __('Añadir Capítulo') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
  <link rel="stylesheet" href="/assets/css/jodit.min.css">
  <style>
    #recorder-section summary { list-style:none; }
    #recorder-section summary::-webkit-details-marker { display:none; }
    #recorder-section > summary {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .4rem .85rem;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--card);
      font-size: .88rem;
      color: var(--muted);
      cursor: pointer;
      user-select: none;
      transition: border-color .12s, color .12s;
    }
    #recorder-section > summary:hover { border-color: var(--accent); color: var(--accent); }
    .rec-body {
      margin-top: .6rem;
      padding: 1rem 1.1rem;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--card);
    }
    .rec-controls { display:flex; align-items:center; gap:.85rem; flex-wrap:wrap; }
    #btn-record {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      padding: .5rem 1.15rem;
      border: 0;
      border-radius: 8px;
      background: #dc2626;
      color: #fff;
      font-size: .9rem;
      font-weight: 600;
      cursor: pointer;
      transition: background .12s;
    }
    #btn-record:hover:not(:disabled) { background: #b91c1c; }
    #btn-record:disabled { background: #f87171; cursor: not-allowed; }
    .rec-dot {
      display: inline-block;
      width: .65rem; height: .65rem;
      background: #fff;
      border-radius: 50%;
      flex-shrink: 0;
    }
    @keyframes rec-pulse { 0%,100%{opacity:1} 50%{opacity:.15} }
    #btn-record.is-recording .rec-dot { animation: rec-pulse .85s ease-in-out infinite; }
    #btn-stop {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      padding: .5rem 1.15rem;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--card);
      color: var(--muted);
      font-size: .9rem;
      font-weight: 600;
      cursor: pointer;
      transition: border-color .12s, color .12s;
    }
    #btn-stop:not(:disabled):hover { border-color: var(--accent); color: var(--accent); }
    #btn-stop:disabled { opacity: .38; cursor: not-allowed; }
    .rec-stop-sq {
      display: inline-block;
      width: .65rem; height: .65rem;
      background: currentColor;
      border-radius: 1px;
      flex-shrink: 0;
    }
    #rec-timer {
      font-family: monospace;
      font-size: 1.15rem;
      font-weight: 700;
      letter-spacing: .04em;
      color: var(--text);
      min-width: 5.5rem;
      transition: color .2s;
    }
    #rec-timer.is-running { color: #dc2626; }
    #rec-status { font-style:italic; font-size:.85rem; color:var(--muted); }
    #rec-preview { width:100%; margin-top:.75rem; }
    #btn-use-recording { margin-top:.6rem; }
  </style>
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
              <option value="scheduled" <?= $form['status'] === 'scheduled' ? 'selected' : '' ?>>scheduled</option>
              <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>published</option>
            </select>
          </label>
        </div>

        <div id="pub_date_row" class="grid two" style="margin-top:.8rem;<?= ($form['status'] !== 'published' && $form['status'] !== 'scheduled') ? 'display:none;' : '' ?>">
          <label>
            <?= __('Fecha de publicación') ?>
            <input id="pub_date" type="datetime-local" name="pub_date" value="<?= esc($form['pub_date']) ?>">
            <span class="help"><?= __('Si se deja vacío se asigna la fecha actual al publicar. Para capítulos programados es obligatoria.') ?></span>
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
          <details id="recorder-section" style="grid-column: 1 / -1; margin-top: .35rem">
            <summary>🎙 <?= __('Grabar desde micrófono') ?></summary>
            <div class="rec-body">
              <div class="rec-controls">
                <button type="button" id="btn-record">
                  <span class="rec-dot"></span>
                  <?= __('Grabar') ?>
                </button>
                <button type="button" id="btn-stop" disabled>
                  <span class="rec-stop-sq"></span>
                  <?= __('Parar') ?>
                </button>
                <span id="rec-timer">00:00:00</span>
                <span id="rec-status"></span>
              </div>
              <audio id="rec-preview" controls style="display:none"></audio>
              <button type="button" id="btn-use-recording" class="btn" style="display:none">✓ <?= __('Usar esta grabación') ?></button>
            </div>
          </details>
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
  <script src="/assets/js/lame.min.js"></script>
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

      // Auto-generar URL mientras el usuario escribe el título, siempre que el
      // campo de link esté vacío (si el usuario ya escribió algo, no se sobreescribe).
      if (titleInput && linkInput) {
        titleInput.addEventListener('input', function () {
          if (linkInput.value.trim() === '') {
            buildEpisodeLink();
          }
        });
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
        pubDateRow.style.display = (statusSelect.value === 'published' || statusSelect.value === 'scheduled') ? '' : 'none';
      });
    }());
  </script>
  <script>
    // Grabación de audio desde el micrófono con codificación MP3 en cliente (lamejs).
    (function () {
      var btnRecord  = document.getElementById('btn-record');
      var btnStop    = document.getElementById('btn-stop');
      var btnUse     = document.getElementById('btn-use-recording');
      var recTimer   = document.getElementById('rec-timer');
      var recStatus  = document.getElementById('rec-status');
      var recPreview = document.getElementById('rec-preview');
      var audioFileInput = document.getElementById('audio_file');

      if (!btnRecord || !btnStop || !audioFileInput) { return; }

      var mediaRecorder  = null;
      var chunks         = [];
      var stream         = null;
      var timerInterval  = null;
      var startTime      = 0;
      var mp3Blob        = null;
      var audioDuration  = 0;

      function padTwo(n) { return String(n).padStart(2, '0'); }

      function formatTime(totalSec) {
        var h = Math.floor(totalSec / 3600);
        var m = Math.floor((totalSec % 3600) / 60);
        var s = totalSec % 60;
        return padTwo(h) + ':' + padTwo(m) + ':' + padTwo(s);
      }

      function startTimer() {
        startTime = Date.now();
        timerInterval = setInterval(function () {
          var elapsed = Math.floor((Date.now() - startTime) / 1000);
          recTimer.textContent = formatTime(elapsed);
        }, 1000);
      }

      function stopTimer() {
        if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
      }

      function float32ToInt16(buffer) {
        var out = new Int16Array(buffer.length);
        for (var i = 0; i < buffer.length; i++) {
          var s = Math.max(-1, Math.min(1, buffer[i]));
          out[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
        }
        return out;
      }

      function encodeToMp3() {
        var blob = new Blob(chunks, { type: 'audio/webm' });
        blob.arrayBuffer().then(function (buffer) {
          var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
          audioCtx.decodeAudioData(buffer, function (audioBuffer) {
            audioDuration = audioBuffer.duration;
            var channels   = audioBuffer.numberOfChannels;
            var sampleRate = audioBuffer.sampleRate;

            if (audioDuration > 1800) {
              recStatus.textContent = 'Codificando MP3 (grabación larga, puede tardar)…';
            }

            var left  = audioBuffer.getChannelData(0);
            var right = channels > 1 ? audioBuffer.getChannelData(1) : left;

            var encoder   = new lamejs.Mp3Encoder(channels, sampleRate, 128);
            var mp3Parts  = [];
            var blockSize = 1152;

            for (var i = 0; i < left.length; i += blockSize) {
              var leftChunk  = float32ToInt16(left.subarray(i, i + blockSize));
              var rightChunk = channels > 1 ? float32ToInt16(right.subarray(i, i + blockSize)) : leftChunk;
              var encoded    = encoder.encodeBuffer(leftChunk, rightChunk);
              if (encoded.length > 0) { mp3Parts.push(encoded); }
            }

            var flushed = encoder.flush();
            if (flushed.length > 0) { mp3Parts.push(flushed); }

            mp3Blob = new Blob(mp3Parts, { type: 'audio/mpeg' });

            var url = URL.createObjectURL(mp3Blob);
            recPreview.src          = url;
            recPreview.style.display = 'block';
            btnUse.style.display    = '';
            recStatus.textContent   = '';
            btnRecord.disabled      = false;
            audioCtx.close();
          }, function () {
            recStatus.textContent = 'Error al decodificar el audio grabado.';
            btnRecord.disabled    = false;
          });
        });
      }

      btnRecord.addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
          alert('Tu navegador no soporta grabación de audio.');
          return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
          stream         = s;
          chunks         = [];
          mp3Blob        = null;
          audioDuration  = 0;
          recPreview.style.display = 'none';
          btnUse.style.display     = 'none';
          recStatus.textContent    = '';

          mediaRecorder = new MediaRecorder(stream);
          mediaRecorder.ondataavailable = function (e) {
            if (e.data && e.data.size > 0) { chunks.push(e.data); }
          };
          mediaRecorder.onstop = encodeToMp3;
          mediaRecorder.start();

          startTimer();
          btnRecord.disabled = true;
          btnRecord.classList.add('is-recording');
          recTimer.classList.add('is-running');
          btnStop.disabled   = false;
        }).catch(function (err) {
          alert('No se pudo acceder al micrófono: ' + err.message);
        });
      });

      btnStop.addEventListener('click', function () {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
          mediaRecorder.stop();
        }
        if (stream) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          stream = null;
        }
        stopTimer();
        btnRecord.classList.remove('is-recording');
        recTimer.classList.remove('is-running');
        recStatus.textContent = 'Codificando MP3…';
        btnStop.disabled      = true;
      });

      btnUse.addEventListener('click', function () {
        if (!mp3Blob) { return; }

        var now      = new Date();
        var fileName = 'grabacion-' + now.getFullYear() + '-' +
          padTwo(now.getMonth() + 1) + '-' + padTwo(now.getDate()) + '.mp3';

        var csrfInput = document.querySelector('input[name="csrf_token"]');
        if (!csrfInput) { return; }

        btnUse.disabled    = true;
        btnUse.textContent = '⏳ Subiendo grabación…';
        recStatus.textContent = '';

        var fd = new FormData();
        fd.append('csrf_token', csrfInput.value);
        fd.append('audio_file', mp3Blob, fileName);

        fetch('upload_audio_ajax.php', { method: 'POST', body: fd })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.error) {
              recStatus.textContent = 'Error al subir: ' + data.error;
              btnUse.disabled    = false;
              btnUse.textContent = '✓ Usar esta grabación';
              return;
            }

            // Rellenar los campos del formulario con los valores del fichero ya subido.
            var audioUrlInput = document.querySelector('input[name="audio_url"]');
            var mimeInput2    = document.getElementById('audio_mime_type');
            var sizeInput2    = document.getElementById('audio_size_bytes');
            var durationInput = document.getElementById('duration');

            if (audioUrlInput) { audioUrlInput.value = data.url; }
            if (mimeInput2)    { mimeInput2.value    = data.mime || 'audio/mpeg'; }
            if (sizeInput2)    { sizeInput2.value    = String(data.size || ''); }
            if (durationInput && audioDuration > 0) {
              var totalSec = Math.floor(audioDuration);
              var dh = Math.floor(totalSec / 3600);
              var dm = Math.floor((totalSec % 3600) / 60);
              var ds = totalSec % 60;
              durationInput.value = padTwo(dh) + ':' + padTwo(dm) + ':' + padTwo(ds);
            }

            btnUse.textContent = '✓ Grabación subida';
            recStatus.textContent = 'Audio guardado correctamente.';

            // Cerrar el panel de grabación
            var details = document.getElementById('recorder-section');
            if (details) { details.removeAttribute('open'); }
          })
          .catch(function () {
            recStatus.textContent = 'Error de red al subir el audio. Inténtalo de nuevo.';
            btnUse.disabled    = false;
            btnUse.textContent = '✓ Usar esta grabación';
          });
      });
    }());
  </script>
</body>
</html>
