<?php

declare(strict_types=1);

// Listado administrativo de episodios:
// - paginación de resultados
// - borrado con confirmación
// - regeneración de feed tras borrado

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/episodes_management_query.php';

session_start();

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$requestedPage = max(1, (int) ($_GET['page'] ?? 1));

$data = loadEpisodesManagementData($dbPath, $requestedPage);
extract($data);  // episodesList, currentPage, totalEpisodes, totalPages, error, notice
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Capítulos Existentes</title>
  <link rel="stylesheet" href="/assets/css/episodes_management.css">
</head>
<body>
  <div class="container">
    <section class="card list-card">
      <div class="title-row">
        <h1>Capítulos Existentes</h1>
        <a class="btn add-link" href="add_episode.php">Añadir Capítulo</a>
      </div>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <?php if (!$episodesList): ?>
        <p class="muted">Todavía no hay capítulos guardados.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Título</th>
                <th>Estado</th>
                <th>Publicación</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($episodesList as $episode): ?>
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
                      <a class="edit-link" href="add_episode.php?episode_id=<?= (int) ($episode['id'] ?? 0) ?>">Editar</a>
                      <form class="inline-form" method="post" action="episodes_management.php?page=<?= $currentPage ?>" onsubmit="return confirm('Se borrará el capítulo de la base de datos. ¿Continuar?');">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="delete_episode_id" value="<?= (int) ($episode['id'] ?? 0) ?>">
                        <input type="hidden" name="return_page" value="<?= $currentPage ?>">
                        <button class="delete-text" type="submit">Borrar</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <nav class="pagination" aria-label="Paginación de capítulos">
          <?php if ($currentPage > 1): ?>
            <a class="page-link" href="episodes_management.php?page=<?= $currentPage - 1 ?>">Anterior</a>
          <?php endif; ?>
          <span class="page-status">Página <?= $currentPage ?> de <?= $totalPages ?> (<?= $totalEpisodes ?> capítulos)</span>
          <?php if ($currentPage < $totalPages): ?>
            <a class="page-link" href="episodes_management.php?page=<?= $currentPage + 1 ?>">Siguiente</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>

      <div class="actions">
        <a class="btn back" href="admin.php">Volver al panel</a>
      </div>
    </section>
  </div>
</body>
</html>
