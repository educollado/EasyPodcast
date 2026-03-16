<?php

declare(strict_types=1);

// Listado administrativo de páginas estáticas.

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

// Procesa borrado enviado desde esta misma página.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_page_id'])) {
    csrf_verify();
    try {
        $pdo = new PDO('sqlite:' . ($dbPath));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $deleteError = deletePage($pdo, (int) $_POST['delete_page_id']);
    } catch (Throwable $e) {
        $deleteError = 'Error al borrar: ' . $e->getMessage();
    }
}

$data = loadPagesManagementData($dbPath);
extract($data);  // pagesList, error, notice

// Si hubo error de borrado, sobreescribe el notice.
if (!empty($deleteError)) {
    $error  = $deleteError;
    $notice = '';
}
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Páginas') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
</head>
<body>
  <?php $currentAdminPage = 'pages'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <section class="card list-card">
      <div class="title-row">
        <h1><?= __('Páginas') ?></h1>
        <a class="btn add-link" href="add_page.php"><?= __('Añadir Página') ?></a>
      </div>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <?php if (!$pagesList): ?>
        <p class="muted"><?= __('Todavía no hay páginas creadas.') ?></p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th><?= __('Título') ?></th>
                <th><?= __('URL') ?></th>
                <th><?= __('Estado') ?></th>
                <th><?= __('Orden') ?></th>
                <th><?= __('Acción') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pagesList as $p): ?>
                <tr>
                  <td>
                    <?php if ((int) ($p['level'] ?? 0) === 1): ?>
                      <span style="margin-left:1.5rem;color:var(--muted);">↳ </span>
                    <?php endif; ?>
                    <?= esc((string) ($p['title'] ?? '')) ?>
                  </td>
                  <td>
                    <a href="/<?= esc((string) ($p['full_path'] ?? '')) ?>" target="_blank" rel="noopener">
                      /<?= esc((string) ($p['full_path'] ?? '')) ?>
                    </a>
                  </td>
                  <td><?= esc((string) ($p['status'] ?? '')) ?></td>
                  <td><?= (int) ($p['sort_order'] ?? 0) ?></td>
                  <td>
                    <div class="row-actions">
                      <a class="edit-link" href="add_page.php?page_id=<?= (int) ($p['id'] ?? 0) ?>"><?= __('Editar') ?></a>
                      <form class="inline-form" method="post" action="pages_management.php"
                            onsubmit="return confirm('¿Borrar la página «<?= esc(addslashes((string) ($p['title'] ?? ''))) ?>»?');">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="delete_page_id" value="<?= (int) ($p['id'] ?? 0) ?>">
                        <button class="delete-text" type="submit"><?= __('Borrar') ?></button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
