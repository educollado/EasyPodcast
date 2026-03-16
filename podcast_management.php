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
<html lang="<?= esc(i18n_html_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Gestión Podcast') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
</head>
<body>
  <?php $currentAdminPage = 'podcast'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Gestión Podcast') ?></h1>
      <p><?= __('Completa los metadatos del canal para rellenar la tabla <strong>podcast</strong>.') ?></p>

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
            <?= __('Título *') ?>
            <input type="text" name="title" value="<?= esc($form['title']) ?>" required>
          </label>
          <label>
            <?= __('URL principal *') ?>
            <input type="url" name="link" value="<?= esc($form['link']) ?>" required>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            <?= __('Descripción *') ?>
            <textarea name="description" required><?= esc($form['description']) ?></textarea>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label>
            <?= __('Idioma') ?>
            <input type="text" name="language" value="<?= esc($form['language']) ?>" placeholder="es-ES">
          </label>
          <?php
            $localeDir   = __DIR__ . '/locale';
            $localeFiles = glob($localeDir . '/*.po') ?: [];
            $localeLabels = [
                'ca_ES' => 'Català',
                'de_DE' => 'Deutsch',
                'en_US' => 'English (US)',
                'es_ES' => 'Español (España)',
                'fr_FR' => 'Français',
                'gl_ES' => 'Galego',
                'it_IT' => 'Italiano',
                'pt_PT' => 'Português (Portugal)',
            ];
            sort($localeFiles);
          ?>
          <label>
            <?= __('Idioma del panel') ?>
            <select name="app_language">
              <?php foreach ($localeFiles as $f):
                $lc = basename($f, '.po');
                $label = $localeLabels[$lc] ?? $lc;
              ?>
                <option value="<?= esc($lc) ?>" <?= $form['app_language'] === $lc ? 'selected' : '' ?>>
                  <?= esc($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small><?= __('Idioma de la interfaz de administración y de las páginas públicas.') ?></small>
          </label>
          <label>
            <?= __('Autor (itunes:author)') ?>
            <input type="text" name="author" value="<?= esc($form['author']) ?>">
          </label>
          <label>
            <?= __('Owner name') ?>
            <input type="text" name="owner_name" value="<?= esc($form['owner_name']) ?>">
          </label>
          <label>
            <?= __('Owner email') ?>
            <input type="email" name="owner_email" value="<?= esc($form['owner_email']) ?>">
          </label>
          <div class="label-block">
            <?= __('Categorías') ?> <small><?= __('(máx. 3 — Apple Podcasts)') ?></small>
            <div class="category-picker">
              <div class="category-chips" id="category-chips"></div>
              <select id="category-select">
                <option value=""><?= __('Añadir categoría...') ?></option>
                <optgroup label="Arts">
                  <option value="Arts">Arts</option>
                  <option value="Books">Books</option>
                  <option value="Design">Design</option>
                  <option value="Fashion &amp; Beauty">Fashion &amp; Beauty</option>
                  <option value="Food">Food</option>
                  <option value="Performing Arts">Performing Arts</option>
                  <option value="Visual Arts">Visual Arts</option>
                </optgroup>
                <optgroup label="Business">
                  <option value="Business">Business</option>
                  <option value="Careers">Careers</option>
                  <option value="Entrepreneurship">Entrepreneurship</option>
                  <option value="Investing">Investing</option>
                  <option value="Management">Management</option>
                  <option value="Marketing">Marketing</option>
                  <option value="Non-Profit">Non-Profit</option>
                </optgroup>
                <optgroup label="Comedy">
                  <option value="Comedy">Comedy</option>
                  <option value="Comedy Interviews">Comedy Interviews</option>
                  <option value="Improv">Improv</option>
                  <option value="Stand-Up">Stand-Up</option>
                </optgroup>
                <optgroup label="Education">
                  <option value="Education">Education</option>
                  <option value="Courses">Courses</option>
                  <option value="How To">How To</option>
                  <option value="Language Learning">Language Learning</option>
                  <option value="Self-Improvement">Self-Improvement</option>
                </optgroup>
                <optgroup label="Fiction">
                  <option value="Fiction">Fiction</option>
                  <option value="Comedy Fiction">Comedy Fiction</option>
                  <option value="Drama">Drama</option>
                  <option value="Science Fiction">Science Fiction</option>
                </optgroup>
                <optgroup label="Government">
                  <option value="Government">Government</option>
                </optgroup>
                <optgroup label="History">
                  <option value="History">History</option>
                </optgroup>
                <optgroup label="Health &amp; Fitness">
                  <option value="Health &amp; Fitness">Health &amp; Fitness</option>
                  <option value="Alternative Health">Alternative Health</option>
                  <option value="Fitness">Fitness</option>
                  <option value="Medicine">Medicine</option>
                  <option value="Mental Health">Mental Health</option>
                  <option value="Nutrition">Nutrition</option>
                  <option value="Sexuality">Sexuality</option>
                </optgroup>
                <optgroup label="Kids &amp; Family">
                  <option value="Kids &amp; Family">Kids &amp; Family</option>
                  <option value="Education for Kids">Education for Kids</option>
                  <option value="Parenting">Parenting</option>
                  <option value="Pets &amp; Animals">Pets &amp; Animals</option>
                  <option value="Stories for Kids">Stories for Kids</option>
                </optgroup>
                <optgroup label="Leisure">
                  <option value="Leisure">Leisure</option>
                  <option value="Animation &amp; Manga">Animation &amp; Manga</option>
                  <option value="Automotive">Automotive</option>
                  <option value="Aviation">Aviation</option>
                  <option value="Crafts">Crafts</option>
                  <option value="Games">Games</option>
                  <option value="Hobbies">Hobbies</option>
                  <option value="Home &amp; Garden">Home &amp; Garden</option>
                  <option value="Video Games">Video Games</option>
                </optgroup>
                <optgroup label="Music">
                  <option value="Music">Music</option>
                  <option value="Music Commentary">Music Commentary</option>
                  <option value="Music History">Music History</option>
                  <option value="Music Interviews">Music Interviews</option>
                </optgroup>
                <optgroup label="News">
                  <option value="News">News</option>
                  <option value="Business News">Business News</option>
                  <option value="Daily News">Daily News</option>
                  <option value="Entertainment News">Entertainment News</option>
                  <option value="News Commentary">News Commentary</option>
                  <option value="Politics">Politics</option>
                  <option value="Sports News">Sports News</option>
                  <option value="Tech News">Tech News</option>
                </optgroup>
                <optgroup label="Religion &amp; Spirituality">
                  <option value="Religion &amp; Spirituality">Religion &amp; Spirituality</option>
                  <option value="Buddhism">Buddhism</option>
                  <option value="Christianity">Christianity</option>
                  <option value="Hinduism">Hinduism</option>
                  <option value="Islam">Islam</option>
                  <option value="Judaism">Judaism</option>
                  <option value="Religion">Religion</option>
                  <option value="Spirituality">Spirituality</option>
                </optgroup>
                <optgroup label="Science">
                  <option value="Science">Science</option>
                  <option value="Astronomy">Astronomy</option>
                  <option value="Chemistry">Chemistry</option>
                  <option value="Earth Sciences">Earth Sciences</option>
                  <option value="Life Sciences">Life Sciences</option>
                  <option value="Mathematics">Mathematics</option>
                  <option value="Natural Sciences">Natural Sciences</option>
                  <option value="Nature">Nature</option>
                  <option value="Physics">Physics</option>
                  <option value="Social Sciences">Social Sciences</option>
                </optgroup>
                <optgroup label="Society &amp; Culture">
                  <option value="Society &amp; Culture">Society &amp; Culture</option>
                  <option value="Documentary">Documentary</option>
                  <option value="Personal Journals">Personal Journals</option>
                  <option value="Philosophy">Philosophy</option>
                  <option value="Places &amp; Travel">Places &amp; Travel</option>
                  <option value="Relationships">Relationships</option>
                </optgroup>
                <optgroup label="Sports">
                  <option value="Sports">Sports</option>
                  <option value="Baseball">Baseball</option>
                  <option value="Basketball">Basketball</option>
                  <option value="Cricket">Cricket</option>
                  <option value="Fantasy Sports">Fantasy Sports</option>
                  <option value="Football">Football</option>
                  <option value="Golf">Golf</option>
                  <option value="Hockey">Hockey</option>
                  <option value="Rugby">Rugby</option>
                  <option value="Running">Running</option>
                  <option value="Soccer">Soccer</option>
                  <option value="Swimming">Swimming</option>
                  <option value="Tennis">Tennis</option>
                  <option value="Track">Track</option>
                  <option value="Volleyball">Volleyball</option>
                  <option value="Wilderness">Wilderness</option>
                  <option value="Wrestling">Wrestling</option>
                </optgroup>
                <optgroup label="Technology">
                  <option value="Technology">Technology</option>
                </optgroup>
                <optgroup label="True Crime">
                  <option value="True Crime">True Crime</option>
                </optgroup>
                <optgroup label="TV &amp; Film">
                  <option value="TV &amp; Film">TV &amp; Film</option>
                  <option value="After Shows">After Shows</option>
                  <option value="Film History">Film History</option>
                  <option value="Film Interviews">Film Interviews</option>
                  <option value="Film Reviews">Film Reviews</option>
                  <option value="TV Reviews">TV Reviews</option>
                </optgroup>
              </select>
              <input type="hidden" name="category" id="category-hidden" value="<?= esc($form['category']) ?>">
            </div>
          </div>
          <label style="align-self: start">
            Explícito
            <select name="explicit">
              <option value="0" <?= $form['explicit'] === '0' ? 'selected' : '' ?>>No</option>
              <option value="1" <?= $form['explicit'] === '1' ? 'selected' : '' ?>>Sí</option>
            </select>
          </label>
          <label>
            <?= __('Imagen del podcast (URL)') ?>
            <input type="url" name="image_url" value="<?= esc($form['image_url']) ?>">
          </label>
          <label>
            <?= __('O subir imagen del podcast') ?>
            <input type="file" name="image_file" accept="image/*">
          </label>
          <label style="align-self: start">
            <?= __('Tipo iTunes') ?>
            <select name="itunes_type">
              <option value="episodic" <?= $form['itunes_type'] === 'episodic' ? 'selected' : '' ?>>episodic</option>
              <option value="serial" <?= $form['itunes_type'] === 'serial' ? 'selected' : '' ?>>serial</option>
            </select>
          </label>
          <label>
            <?= __('Cantidad de elementos del Feed RSS') ?>
            <input type="number" min="0" step="1" name="rss_item_limit" value="<?= esc($form['rss_item_limit']) ?>">
            <small><?= __('Nota: 0 significa infinitos (sin límite).') ?></small>
          </label>
          <label>
            <?= __('Cantidad de elementos de la portada') ?>
            <input type="number" min="1" step="1" name="home_items_per_page" value="<?= esc($form['home_items_per_page']) ?>">
            <small><?= __('Controla cuántos episodios se muestran por página en la portada.') ?></small>
          </label>
          <label class="inline-checkbox">
            <input type="checkbox" name="write_audio_metadata" value="1" <?= $form['write_audio_metadata'] === '1' ? 'checked' : '' ?>>
            <span><?= __('Escribir metadatos ID3 en MP3 al subir episodio') ?></span>
            <small><?= __('Usa datos del episodio/podcast para título, artista, álbum, fecha, comentario y pista.') ?></small>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            <?= __('Copyright') ?>
            <input type="text" name="copyright" value="<?= esc($form['copyright']) ?>">
          </label>
        </div>

        <div class="actions">
          <button class="btn" type="submit"><?= __('Guardar podcast') ?></button>
        </div>
      </form>
    </main>
  </div>
  <script>
  // Selector de categorías Apple Podcasts — máx. 3, almacenadas en hidden input.
  (function () {
    var MAX = 3;
    var hidden = document.getElementById('category-hidden');
    var chipsEl = document.getElementById('category-chips');
    var sel = document.getElementById('category-select');

    // Carga valores ya guardados (el hidden viene con el valor de BD).
    var selected = hidden.value
      ? hidden.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean)
      : [];

    function render() {
      chipsEl.innerHTML = '';
      selected.forEach(function (cat) {
        var chip = document.createElement('span');
        chip.className = 'chip';
        var text = document.createTextNode(cat + ' ');
        chip.appendChild(text);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'chip-remove';
        btn.textContent = '×';
        btn.setAttribute('aria-label', 'Eliminar ' + cat);
        btn.onclick = function () { remove(cat); };
        chip.appendChild(btn);
        chipsEl.appendChild(chip);
      });
      hidden.value = selected.join(', ');
      sel.disabled = selected.length >= MAX;
      sel.value = '';
    }

    function remove(cat) {
      selected = selected.filter(function (c) { return c !== cat; });
      render();
    }

    sel.addEventListener('change', function () {
      var val = this.value;
      if (!val || selected.length >= MAX) { this.value = ''; return; }
      if (selected.indexOf(val) === -1) { selected.push(val); }
      render();
    });

    render();
  })();
  </script>
</body>
</html>
