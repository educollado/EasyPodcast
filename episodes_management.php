<?php

declare(strict_types=1);

// Listado administrativo de episodios:
// - paginación de resultados
// - borrado con confirmación
// - regeneración de feed tras borrado

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/episodes_management_query.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';

startSecureSession();

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$requestedPage      = max(1, (int) ($_GET['page'] ?? 1));
$requestedDraftPage = max(1, (int) ($_GET['draft_page'] ?? 1));
$searchQuery        = trim((string) ($_GET['q'] ?? ''));
$deleteConfirmMessage = __('Se borrará el capítulo de la base de datos. El audio y la imagen se eliminarán del servidor si ningún otro capítulo los usa. ¿Continuar?');

$data = loadEpisodesManagementData($dbPath, $requestedPage, $requestedDraftPage, $searchQuery);
extract($data);  // searchQuery, searchResults, draftEpisodes, scheduledEpisodes, publishedEpisodes, draftCurrentPage, draftTotalPages, totalDrafts, totalScheduled, currentPage, totalPublished, totalPages, error, notice
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Capítulos Publicados') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'episodes'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <section class="card list-card">
      <div class="title-row">
        <form class="search-form" method="get" action="episodes_management.php">
          <input class="search-input" type="search" name="q" value="<?= esc($searchQuery) ?>" placeholder="<?= esc(__('Buscar episodios')) ?>">
          <button class="btn" type="submit"><?= __('Buscar') ?></button>
          <?php if ($searchQuery !== ''): ?>
            <a class="btn btn-secondary" href="episodes_management.php"><?= __('Limpiar búsqueda') ?></a>
          <?php endif; ?>
        </form>
        <a class="btn add-link" href="add_episode.php"><?= __('Añadir Capítulo') ?></a>
      </div>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <?php if ($searchQuery !== ''): ?>

        <?php if (!$searchResults): ?>
          <p class="muted"><?= esc(__('No se encontraron capítulos para "%s".', $searchQuery)) ?></p>
        <?php else: ?>
          <h2 class="search-results-title"><?= esc(__('Resultados para "%s": %d', $searchQuery, count($searchResults))) ?></h2>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><?= __('ID') ?></th>
                  <th><?= __('Título') ?></th>
                  <th><?= __('Estado') ?></th>
                  <th><?= __('Publicación') ?></th>
                  <th><?= __('Acción') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($searchResults as $episode): ?>
                  <tr>
                    <td><?= (int) ($episode['id'] ?? 0) ?></td>
                    <td>
                      <?= esc((string) ($episode['title'] ?? '')) ?><br>
                      <small class="muted guid"><?= esc((string) ($episode['guid'] ?? '')) ?></small>
                    </td>
                    <td><?= esc((string) ($episode['status'] ?? '')) ?></td>
                    <td><?= esc((string) ($episode['pub_date'] ?? '')) ?></td>
                    <td>
                      <div class="row-actions">
                        <a class="edit-link" href="add_episode.php?episode_id=<?= (int) ($episode['id'] ?? 0) ?>"><?= __('Editar') ?></a>
                        <a class="edit-link" href="<?= esc(resolveEpisodeHref((string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), (string) ($episode['title'] ?? ''))) ?>" target="_blank"><?= __('Vista previa') ?></a>
                        <form class="inline-form" method="post" action="episodes_management.php?q=<?= esc(urlencode($searchQuery)) ?>" data-confirm-message="<?= esc($deleteConfirmMessage) ?>">
                          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                          <input type="hidden" name="delete_episode_id" value="<?= (int) ($episode['id'] ?? 0) ?>">
                          <input type="hidden" name="return_page" value="1">
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

      <?php elseif (!$totalDrafts && !$totalScheduled && !$totalPublished): ?>
        <p class="muted"><?= __('Todavía no hay capítulos guardados.') ?></p>
      <?php else: ?>

        <?php if ($totalDrafts > 0): ?>
          <h1><?= __('Capítulos en Borrador') ?></h1>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><?= __('ID') ?></th>
                  <th><?= __('Título') ?></th>
                  <th><?= __('Publicación') ?></th>
                  <th><?= __('Acción') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($draftEpisodes as $episode): ?>
                  <tr>
                    <td><?= (int) ($episode['id'] ?? 0) ?></td>
                    <td>
                      <?= esc((string) ($episode['title'] ?? '')) ?><br>
                      <small class="muted guid"><?= esc((string) ($episode['guid'] ?? '')) ?></small>
                    </td>
                    <td><?= esc((string) ($episode['pub_date'] ?? '')) ?></td>
                    <td>
                      <div class="row-actions">
                        <a class="edit-link" href="add_episode.php?episode_id=<?= (int) ($episode['id'] ?? 0) ?>"><?= __('Editar') ?></a>
                        <?php $draftPreviewHref = resolveEpisodeHref((string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), (string) ($episode['title'] ?? '')); ?>
                        <?php if ($draftPreviewHref !== ''): ?>
                          <a class="edit-link" href="<?= esc($draftPreviewHref) ?>" target="_blank"><?= __('Vista previa') ?></a>
                        <?php endif; ?>
                        <form class="inline-form" method="post" action="episodes_management.php?page=<?= $currentPage ?>&draft_page=<?= $draftCurrentPage ?>" data-confirm-message="<?= esc($deleteConfirmMessage) ?>">
                          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                          <input type="hidden" name="delete_episode_id" value="<?= (int) ($episode['id'] ?? 0) ?>">
                          <input type="hidden" name="return_page" value="<?= $currentPage ?>">
                          <button class="delete-text" type="submit"><?= __('Borrar') ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <nav class="pagination" aria-label="Paginación de borradores">
            <span class="page-status"><?= esc(__('Página %d de %d (%d capítulos)', $draftCurrentPage, $draftTotalPages, $totalDrafts)) ?></span>
            <div class="links">
              <?php if ($draftCurrentPage > 1): ?>
                <a class="page-link" href="episodes_management.php?page=<?= $currentPage ?>&draft_page=<?= $draftCurrentPage - 1 ?>"><?= __('Anterior') ?></a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $draftTotalPages; $p++): ?>
                <a class="page-link<?= $p === $draftCurrentPage ? ' active' : '' ?>" href="episodes_management.php?page=<?= $currentPage ?>&draft_page=<?= $p ?>"><?= $p ?></a>
              <?php endfor; ?>
              <?php if ($draftCurrentPage < $draftTotalPages): ?>
                <a class="page-link" href="episodes_management.php?page=<?= $currentPage ?>&draft_page=<?= $draftCurrentPage + 1 ?>"><?= __('Siguiente') ?></a>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>

        <?php if ($totalScheduled > 0): ?>
          <h1><?= __('Capítulos Programados') ?></h1>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><?= __('ID') ?></th>
                  <th><?= __('Título') ?></th>
                  <th><?= __('Publicación programada') ?></th>
                  <th><?= __('Acción') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($scheduledEpisodes as $episode): ?>
                  <tr>
                    <td><?= (int) ($episode['id'] ?? 0) ?></td>
                    <td>
                      <?= esc((string) ($episode['title'] ?? '')) ?><br>
                      <small class="muted guid"><?= esc((string) ($episode['guid'] ?? '')) ?></small>
                    </td>
                    <td><?= esc((string) ($episode['pub_date'] ?? '')) ?></td>
                    <td>
                      <div class="row-actions">
                        <a class="edit-link" href="add_episode.php?episode_id=<?= (int) ($episode['id'] ?? 0) ?>"><?= __('Editar') ?></a>
                        <?php $scheduledPreviewHref = resolveEpisodeHref((string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), (string) ($episode['title'] ?? '')); ?>
                        <?php if ($scheduledPreviewHref !== ''): ?>
                          <a class="edit-link" href="<?= esc($scheduledPreviewHref) ?>" target="_blank"><?= __('Vista previa') ?></a>
                        <?php endif; ?>
                        <form class="inline-form" method="post" action="episodes_management.php?page=<?= $currentPage ?>&draft_page=<?= $draftCurrentPage ?>" data-confirm-message="<?= esc($deleteConfirmMessage) ?>">
                          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                          <input type="hidden" name="delete_episode_id" value="<?= (int) ($episode['id'] ?? 0) ?>">
                          <input type="hidden" name="return_page" value="<?= $currentPage ?>">
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

        <h1><?= __('Capítulos Publicados') ?></h1>
        <?php if (!$publishedEpisodes): ?>
          <p class="muted"><?= __('Todavía no hay capítulos publicados.') ?></p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th><?= __('ID') ?></th>
                  <th><?= __('Título') ?></th>
                  <th><?= __('Publicación') ?></th>
                  <th><?= __('Acción') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($publishedEpisodes as $episode): ?>
                  <tr>
                    <td><?= (int) ($episode['id'] ?? 0) ?></td>
                    <td>
                      <?= esc((string) ($episode['title'] ?? '')) ?><br>
                      <small class="muted guid"><?= esc((string) ($episode['guid'] ?? '')) ?></small>
                    </td>
                    <td><?= esc((string) ($episode['pub_date'] ?? '')) ?></td>
                    <td>
                      <div class="row-actions">
                        <a class="edit-link" href="add_episode.php?episode_id=<?= (int) ($episode['id'] ?? 0) ?>"><?= __('Editar') ?></a>
                        <a class="edit-link" href="<?= esc(resolveEpisodeHref((string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), (string) ($episode['title'] ?? ''))) ?>" target="_blank"><?= __('Vista previa') ?></a>
                        <form class="inline-form" method="post" action="episodes_management.php?page=<?= $currentPage ?>&draft_page=<?= $draftCurrentPage ?>" data-confirm-message="<?= esc($deleteConfirmMessage) ?>">
                          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                          <input type="hidden" name="delete_episode_id" value="<?= (int) ($episode['id'] ?? 0) ?>">
                          <input type="hidden" name="return_page" value="<?= $currentPage ?>">
                          <button class="delete-text" type="submit"><?= __('Borrar') ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <nav class="pagination" aria-label="Paginación de capítulos publicados">
            <span class="page-status"><?= esc(__('Página %d de %d (%d capítulos)', $currentPage, $totalPages, $totalPublished)) ?></span>
            <div class="links">
              <?php if ($currentPage > 1): ?>
                <a class="page-link" href="episodes_management.php?page=<?= $currentPage - 1 ?>&draft_page=<?= $draftCurrentPage ?>"><?= __('Anterior') ?></a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="page-link<?= $p === $currentPage ? ' active' : '' ?>" href="episodes_management.php?page=<?= $p ?>&draft_page=<?= $draftCurrentPage ?>"><?= $p ?></a>
              <?php endfor; ?>
              <?php if ($currentPage < $totalPages): ?>
                <a class="page-link" href="episodes_management.php?page=<?= $currentPage + 1 ?>&draft_page=<?= $draftCurrentPage ?>"><?= __('Siguiente') ?></a>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>

      <?php endif; ?>

    </section>
  </div>
</body>
</html>
