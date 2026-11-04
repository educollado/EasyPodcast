<?php

declare(strict_types=1);

// Panel de gestión de metadatos del podcast (una sola fila de canal).

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/podcast_management_handler.php';

startSecureSession();
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
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Gestión Podcast') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/podcast-management.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/podcast-management.css') ?>">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'podcast'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Gestión Podcast') ?></h1>
      <p><?= __('Completa los metadatos del canal para rellenar la tabla <strong>podcast</strong>.') ?></p>
      <?php if ($showSummaryVisibilityOption): ?>
        <label class="inline-checkbox summary-visibility-option">
          <input type="checkbox" name="include_in_summary" value="1" form="podcast-metadata-form" <?= $form['include_in_summary'] === '1' ? 'checked' : '' ?>>
          <span><?= __('Mostrar este podcast en la página principal de resumen.') ?></span>
        </label>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form id="podcast-metadata-form" method="post" action="podcast_management.php" autocomplete="off" enctype="multipart/form-data">
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

        <div class="grid grid-section">
          <label>
            <?= __('Descripción *') ?>
            <textarea name="description" required><?= esc($form['description']) ?></textarea>
          </label>
        </div>

        <div class="grid two grid-section">
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
                'zh_CN' => '简体中文',
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
              <select id="category-select" data-remove-label="<?= esc(__('Eliminar categoría %s')) ?>">
                <option value=""><?= __('Añadir categoría...') ?></option>
                <optgroup label="<?= esc(__('Arts')) ?>">
                  <option value="Arts"><?= esc(__('Arts')) ?></option>
                  <option value="Books"><?= esc(__('Books')) ?></option>
                  <option value="Design"><?= esc(__('Design')) ?></option>
                  <option value="Fashion &amp; Beauty"><?= esc(__('Fashion & Beauty')) ?></option>
                  <option value="Food"><?= esc(__('Food')) ?></option>
                  <option value="Performing Arts"><?= esc(__('Performing Arts')) ?></option>
                  <option value="Visual Arts"><?= esc(__('Visual Arts')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Business')) ?>">
                  <option value="Business"><?= esc(__('Business')) ?></option>
                  <option value="Careers"><?= esc(__('Careers')) ?></option>
                  <option value="Entrepreneurship"><?= esc(__('Entrepreneurship')) ?></option>
                  <option value="Investing"><?= esc(__('Investing')) ?></option>
                  <option value="Management"><?= esc(__('Management')) ?></option>
                  <option value="Marketing"><?= esc(__('Marketing')) ?></option>
                  <option value="Non-Profit"><?= esc(__('Non-Profit')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Comedy')) ?>">
                  <option value="Comedy"><?= esc(__('Comedy')) ?></option>
                  <option value="Comedy Interviews"><?= esc(__('Comedy Interviews')) ?></option>
                  <option value="Improv"><?= esc(__('Improv')) ?></option>
                  <option value="Stand-Up"><?= esc(__('Stand-Up')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Education')) ?>">
                  <option value="Education"><?= esc(__('Education')) ?></option>
                  <option value="Courses"><?= esc(__('Courses')) ?></option>
                  <option value="How To"><?= esc(__('How To')) ?></option>
                  <option value="Language Learning"><?= esc(__('Language Learning')) ?></option>
                  <option value="Self-Improvement"><?= esc(__('Self-Improvement')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Fiction')) ?>">
                  <option value="Fiction"><?= esc(__('Fiction')) ?></option>
                  <option value="Comedy Fiction"><?= esc(__('Comedy Fiction')) ?></option>
                  <option value="Drama"><?= esc(__('Drama')) ?></option>
                  <option value="Science Fiction"><?= esc(__('Science Fiction')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Government')) ?>">
                  <option value="Government"><?= esc(__('Government')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('History')) ?>">
                  <option value="History"><?= esc(__('History')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Health & Fitness')) ?>">
                  <option value="Health &amp; Fitness"><?= esc(__('Health & Fitness')) ?></option>
                  <option value="Alternative Health"><?= esc(__('Alternative Health')) ?></option>
                  <option value="Fitness"><?= esc(__('Fitness')) ?></option>
                  <option value="Medicine"><?= esc(__('Medicine')) ?></option>
                  <option value="Mental Health"><?= esc(__('Mental Health')) ?></option>
                  <option value="Nutrition"><?= esc(__('Nutrition')) ?></option>
                  <option value="Sexuality"><?= esc(__('Sexuality')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Kids & Family')) ?>">
                  <option value="Kids &amp; Family"><?= esc(__('Kids & Family')) ?></option>
                  <option value="Education for Kids"><?= esc(__('Education for Kids')) ?></option>
                  <option value="Parenting"><?= esc(__('Parenting')) ?></option>
                  <option value="Pets &amp; Animals"><?= esc(__('Pets & Animals')) ?></option>
                  <option value="Stories for Kids"><?= esc(__('Stories for Kids')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Leisure')) ?>">
                  <option value="Leisure"><?= esc(__('Leisure')) ?></option>
                  <option value="Animation &amp; Manga"><?= esc(__('Animation & Manga')) ?></option>
                  <option value="Automotive"><?= esc(__('Automotive')) ?></option>
                  <option value="Aviation"><?= esc(__('Aviation')) ?></option>
                  <option value="Crafts"><?= esc(__('Crafts')) ?></option>
                  <option value="Games"><?= esc(__('Games')) ?></option>
                  <option value="Hobbies"><?= esc(__('Hobbies')) ?></option>
                  <option value="Home &amp; Garden"><?= esc(__('Home & Garden')) ?></option>
                  <option value="Video Games"><?= esc(__('Video Games')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Music')) ?>">
                  <option value="Music"><?= esc(__('Music')) ?></option>
                  <option value="Music Commentary"><?= esc(__('Music Commentary')) ?></option>
                  <option value="Music History"><?= esc(__('Music History')) ?></option>
                  <option value="Music Interviews"><?= esc(__('Music Interviews')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('News')) ?>">
                  <option value="News"><?= esc(__('News')) ?></option>
                  <option value="Business News"><?= esc(__('Business News')) ?></option>
                  <option value="Daily News"><?= esc(__('Daily News')) ?></option>
                  <option value="Entertainment News"><?= esc(__('Entertainment News')) ?></option>
                  <option value="News Commentary"><?= esc(__('News Commentary')) ?></option>
                  <option value="Politics"><?= esc(__('Politics')) ?></option>
                  <option value="Sports News"><?= esc(__('Sports News')) ?></option>
                  <option value="Tech News"><?= esc(__('Tech News')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Religion & Spirituality')) ?>">
                  <option value="Religion &amp; Spirituality"><?= esc(__('Religion & Spirituality')) ?></option>
                  <option value="Buddhism"><?= esc(__('Buddhism')) ?></option>
                  <option value="Christianity"><?= esc(__('Christianity')) ?></option>
                  <option value="Hinduism"><?= esc(__('Hinduism')) ?></option>
                  <option value="Islam"><?= esc(__('Islam')) ?></option>
                  <option value="Judaism"><?= esc(__('Judaism')) ?></option>
                  <option value="Religion"><?= esc(__('Religion')) ?></option>
                  <option value="Spirituality"><?= esc(__('Spirituality')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Science')) ?>">
                  <option value="Science"><?= esc(__('Science')) ?></option>
                  <option value="Astronomy"><?= esc(__('Astronomy')) ?></option>
                  <option value="Chemistry"><?= esc(__('Chemistry')) ?></option>
                  <option value="Earth Sciences"><?= esc(__('Earth Sciences')) ?></option>
                  <option value="Life Sciences"><?= esc(__('Life Sciences')) ?></option>
                  <option value="Mathematics"><?= esc(__('Mathematics')) ?></option>
                  <option value="Natural Sciences"><?= esc(__('Natural Sciences')) ?></option>
                  <option value="Nature"><?= esc(__('Nature')) ?></option>
                  <option value="Physics"><?= esc(__('Physics')) ?></option>
                  <option value="Social Sciences"><?= esc(__('Social Sciences')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Society & Culture')) ?>">
                  <option value="Society &amp; Culture"><?= esc(__('Society & Culture')) ?></option>
                  <option value="Documentary"><?= esc(__('Documentary')) ?></option>
                  <option value="Personal Journals"><?= esc(__('Personal Journals')) ?></option>
                  <option value="Philosophy"><?= esc(__('Philosophy')) ?></option>
                  <option value="Places &amp; Travel"><?= esc(__('Places & Travel')) ?></option>
                  <option value="Relationships"><?= esc(__('Relationships')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Sports')) ?>">
                  <option value="Sports"><?= esc(__('Sports')) ?></option>
                  <option value="Baseball"><?= esc(__('Baseball')) ?></option>
                  <option value="Basketball"><?= esc(__('Basketball')) ?></option>
                  <option value="Cricket"><?= esc(__('Cricket')) ?></option>
                  <option value="Fantasy Sports"><?= esc(__('Fantasy Sports')) ?></option>
                  <option value="Football"><?= esc(__('Football')) ?></option>
                  <option value="Golf"><?= esc(__('Golf')) ?></option>
                  <option value="Hockey"><?= esc(__('Hockey')) ?></option>
                  <option value="Rugby"><?= esc(__('Rugby')) ?></option>
                  <option value="Running"><?= esc(__('Running')) ?></option>
                  <option value="Soccer"><?= esc(__('Soccer')) ?></option>
                  <option value="Swimming"><?= esc(__('Swimming')) ?></option>
                  <option value="Tennis"><?= esc(__('Tennis')) ?></option>
                  <option value="Track"><?= esc(__('Track')) ?></option>
                  <option value="Volleyball"><?= esc(__('Volleyball')) ?></option>
                  <option value="Wilderness"><?= esc(__('Wilderness')) ?></option>
                  <option value="Wrestling"><?= esc(__('Wrestling')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('Technology')) ?>">
                  <option value="Technology"><?= esc(__('Technology')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('True Crime')) ?>">
                  <option value="True Crime"><?= esc(__('True Crime')) ?></option>
                </optgroup>
                <optgroup label="<?= esc(__('TV & Film')) ?>">
                  <option value="TV &amp; Film"><?= esc(__('TV & Film')) ?></option>
                  <option value="After Shows"><?= esc(__('After Shows')) ?></option>
                  <option value="Film History"><?= esc(__('Film History')) ?></option>
                  <option value="Film Interviews"><?= esc(__('Film Interviews')) ?></option>
                  <option value="Film Reviews"><?= esc(__('Film Reviews')) ?></option>
                  <option value="TV Reviews"><?= esc(__('TV Reviews')) ?></option>
                </optgroup>
              </select>
              <input type="hidden" name="category" id="category-hidden" value="<?= esc($form['category']) ?>">
            </div>
          </div>
          <label>
            <?= __('Palabras clave (iTunes/iVoox)') ?>
            <input type="text" name="oc_itunes_keywords" value="<?= esc($form['oc_itunes_keywords']) ?>" maxlength="255">
            <small><?= __('Sepáralas por comas. Se publican en el feed como itunes:keywords.') ?></small>
          </label>
          <label class="align-start">
            <?= __('Explícito') ?>
            <select name="explicit">
              <option value="0" <?= $form['explicit'] === '0' ? 'selected' : '' ?>><?= __('No') ?></option>
              <option value="1" <?= $form['explicit'] === '1' ? 'selected' : '' ?>><?= __('Sí') ?></option>
            </select>
          </label>
          <label class="align-start">
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

        <section class="podcast-images grid-section" aria-label="<?= esc(__('Imágenes del podcast')) ?>">
          <article class="podcast-image-card">
            <h2><?= __('Imagen del podcast') ?></h2>
            <div class="podcast-image-preview podcast-image-preview-cover">
              <img
                id="podcast-image-preview"
                <?php if ($form['image_url'] !== ''): ?>src="<?= esc($form['image_url']) ?>"<?php endif; ?>
                alt="<?= esc(__('Vista previa de la imagen del podcast')) ?>"
                <?= $form['image_url'] === '' ? 'hidden' : '' ?>
              >
              <span id="podcast-image-placeholder" <?= $form['image_url'] !== '' ? 'hidden' : '' ?>>
                <?= __('Sin imagen') ?>
              </span>
            </div>
            <label>
              <?= __('Imagen del podcast (URL)') ?>
              <input
                type="url"
                name="image_url"
                value="<?= esc($form['image_url']) ?>"
                data-image-preview="podcast-image-preview"
              >
            </label>
            <label>
              <?= __('O subir imagen del podcast') ?>
              <input
                type="file"
                name="image_file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                data-image-preview="podcast-image-preview"
              >
            </label>
          </article>

          <article class="podcast-image-card">
            <h2><?= __('Imagen del hero') ?></h2>
            <div class="podcast-image-preview podcast-image-preview-hero">
              <img
                id="hero-image-preview"
                <?php if ($form['hero_image_url'] !== ''): ?>src="<?= esc($form['hero_image_url']) ?>"<?php endif; ?>
                alt="<?= esc(__('Vista previa de la imagen del hero')) ?>"
                <?= $form['hero_image_url'] === '' ? 'hidden' : '' ?>
              >
              <span id="hero-image-placeholder" <?= $form['hero_image_url'] !== '' ? 'hidden' : '' ?>>
                <?= __('Sin imagen') ?>
              </span>
            </div>
            <label>
              <?= __('Imagen del hero (URL)') ?>
              <input
                type="url"
                name="hero_image_url"
                value="<?= esc($form['hero_image_url']) ?>"
                data-image-preview="hero-image-preview"
              >
              <small><?= __('Déjala vacía para mantener la cabecera actual sin hero.') ?></small>
            </label>
            <label>
              <?= __('O subir imagen para el hero') ?>
              <input
                type="file"
                name="hero_image_file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                data-image-preview="hero-image-preview"
              >
              <small><?= __('La imagen subida se recorta y optimiza automáticamente para la cabecera.') ?></small>
              <small><?= __('La imagen se recortará para cubrir la cabecera sin cambiar su tamaño.') ?></small>
            </label>
          </article>
        </section>

        <div class="grid grid-section">
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
  <script src="/assets/js/podcast_management.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/podcast_management.js') ?>"></script>
</body>
</html>
