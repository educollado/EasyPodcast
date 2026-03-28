<?php

declare(strict_types=1);

// Panel CRUD de páginas estáticas.

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/page_save_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadAddPageData($dbPath);
extract($data);  // form, isEditing, editingPageId, topLevelPages, error, notice
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEditing ? __('Editar Página') : __('Añadir Página') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
  <link rel="stylesheet" href="/assets/css/jodit.min.css">
</head>
<body>
  <?php $currentAdminPage = 'pages'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= $isEditing ? __('Editar Página') : __('Añadir Página') ?></h1>
      <p><?= $isEditing ? __('Edita la página seleccionada.') : __('Crea una nueva página estática.') ?></p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="add_page.php<?= $isEditing && $editingPageId !== null ? '?page_id=' . (int) $editingPageId : '' ?>" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <?php if ($isEditing && !empty($form['full_path'])): ?>
          <input type="hidden" name="current_full_path" value="<?= esc((string) $form['full_path']) ?>">
        <?php endif; ?>
        <?php if ($isEditing && $editingPageId !== null): ?>
          <input type="hidden" name="page_id" value="<?= (int) $editingPageId ?>">
        <?php endif; ?>

        <div class="grid two">
          <label>
            Título *
            <input id="page_title" type="text" name="title" value="<?= esc($form['title']) ?>" required>
          </label>
          <label>
            Estado
            <select name="status">
              <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>draft</option>
              <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>published</option>
            </select>
          </label>
        </div>

        <div class="grid two" style="margin-top:.8rem;">
          <label>
            <?= __('Slug *') ?>
            <input id="page_slug" type="text" name="slug" value="<?= esc($form['slug']) ?>"
                   pattern="[a-z0-9-]+" required placeholder="mi-pagina">
            <span class="help"><?= __('Solo letras minúsculas, números y guiones. Se genera desde el título.') ?></span>
          </label>
          <label>
            <?= __('Página padre') ?>
            <select name="parent_id">
              <option value=""><?= __('— Sin padre (primer nivel) —') ?></option>
              <?php foreach ($topLevelPages as $tp): ?>
                <?php
                  // En edición, excluir la propia página del selector de padre.
                  if ($isEditing && (int) $tp['id'] === $editingPageId) {
                      continue;
                  }
                ?>
                <option value="<?= (int) $tp['id'] ?>"
                  <?= $form['parent_id'] === (int) $tp['id'] ? 'selected' : '' ?>>
                  <?= esc((string) $tp['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="grid two" style="margin-top:.8rem;">
          <label>
            <?= __('Orden (numérico, menor = antes)') ?>
            <input type="number" name="sort_order" min="0" step="1" value="<?= (int) $form['sort_order'] ?>">
          </label>
        </div>

        <div class="grid" style="margin-top:.8rem;">
          <label>
            <?= __('Contenido') ?>
            <textarea id="page_content" name="content"><?= esc($form['content']) ?></textarea>
          </label>
        </div>

        <div class="actions" style="margin-top:1rem;">
          <?php if ($isEditing): ?>
            <a class="btn" href="<?= esc(buildPagePreviewPath($form)) ?>" target="_blank" rel="noopener"><?= __('Vista previa') ?></a>
          <?php endif; ?>
          <a class="btn" href="pages_management.php"><?= __('Volver a la lista') ?></a>
          <button class="btn" type="submit"><?= $isEditing ? __('Actualizar página') : __('Guardar página') ?></button>
        </div>
      </form>
    </main>
  </div>
  <script src="/assets/js/jodit.min.js"></script>
  <script>
    (function () {
      var contentArea = document.getElementById('page_content');
      if (contentArea) {
        Jodit.make(contentArea, {
          language: 'es',
          toolbar: true,
          buttons: 'paragraph,fontsize,|,bold,italic,underline,strikethrough,superscript,subscript,|,copyformat,eraser,clean,|,ul,ol,|,indent,outdent,|,left,center,right,justify,|,link,image,video,table,hr,|,undo,redo,|,find,preview,fullsize,source',
          toolbarAdaptive: false,
          height: 300,
          enter: 'p',
          cleanHTML: { fillEmptyParagraph: false }
        });
      }

      // Auto-genera slug desde el título (solo si el slug está vacío o no ha sido editado manualmente).
      var titleInput = document.getElementById('page_title');
      var slugInput  = document.getElementById('page_slug');
      var slugDirty  = slugInput && slugInput.value !== '';

      function slugify(value) {
        return value
          .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '') || '';
      }

      if (titleInput && slugInput) {
        slugInput.addEventListener('input', function () { slugDirty = true; });
        titleInput.addEventListener('input', function () {
          if (!slugDirty) {
            slugInput.value = slugify(titleInput.value);
          }
        });
      }
    }());
  </script>
</body>
</html>
