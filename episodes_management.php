<?php

declare(strict_types=1);

// Listado administrativo de episodios:
// - paginación de resultados
// - borrado con confirmación
// - regeneración de feed tras borrado

require_once __DIR__ . '/feed_builder.php';
require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/sitemap_builder.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$notice = '';
$episodesList = [];
$perPage = 50;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalEpisodes = 0;
$totalPages = 1;

if (isset($_GET['status']) && $_GET['status'] === 'deleted') {
    $notice = 'Capítulo borrado correctamente.';
}
if (isset($_GET['status']) && $_GET['status'] === 'delete_error') {
    $error = 'No se encontró el capítulo que se intentó borrar.';
}
if (isset($_GET['status']) && $_GET['status'] === 'feed_warning') {
    $notice = 'Capítulo borrado correctamente. (Aviso: no se pudo regenerar feed.xml/sitemap.xml)';
}
if (isset($_GET['status']) && $_GET['status'] === 'cache_warning') {
    $notice = 'Capítulo borrado correctamente. (Aviso: no se pudo limpiar completamente la caché)';
}
if (isset($_GET['status']) && $_GET['status'] === 'feed_cache_warning') {
    $notice = 'Capítulo borrado correctamente. (Aviso: no se pudo regenerar feed.xml/sitemap.xml ni limpiar completamente la caché)';
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS episodes (
          id INTEGER PRIMARY KEY,
          guid TEXT NOT NULL UNIQUE,
          title TEXT NOT NULL,
          description TEXT NOT NULL,
          link TEXT,
          pub_date TEXT NOT NULL,
          audio_url TEXT NOT NULL,
          audio_mime_type TEXT NOT NULL,
          audio_size_bytes INTEGER NOT NULL,
          duration TEXT,
          explicit INTEGER,
          season_number INTEGER,
          episode_number INTEGER,
          episode_type TEXT,
          image_url TEXT,
          author TEXT,
          status TEXT NOT NULL DEFAULT 'draft',
          created_at TEXT DEFAULT (datetime('now')),
          updated_at TEXT DEFAULT (datetime('now'))
        )"
    );

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_episodes_status_pubdate ON episodes(status, pub_date)");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        // Acción de borrado con redirección PRG para evitar reenvío de formulario.
        $deleteEpisodeId = (int) ($_POST['delete_episode_id'] ?? 0);
        $returnPage = max(1, (int) ($_POST['return_page'] ?? 1));

        if ($deleteEpisodeId > 0) {
            $deleteStmt = $pdo->prepare('DELETE FROM episodes WHERE id = :id');
            $deleteStmt->execute([':id' => $deleteEpisodeId]);

            if ($deleteStmt->rowCount() > 0) {
                $status = 'deleted';
                $feedOk = true;
                try {
                    writePodcastFeedFile($pdo, __DIR__ . '/feed.xml', resolveFeedSelfHref($pdo));
                    writePodcastSitemapFile($pdo, __DIR__ . '/sitemap.xml');
                } catch (Throwable $feedError) {
                    $feedOk = false;
                }
                $cacheOk = clearWebCache();
                if (!$feedOk && !$cacheOk) {
                    $status = 'feed_cache_warning';
                } elseif (!$feedOk) {
                    $status = 'feed_warning';
                } elseif (!$cacheOk) {
                    $status = 'cache_warning';
                }
                header('Location: episodes_management.php?page=' . $returnPage . '&status=' . $status);
                exit;
            }

            header('Location: episodes_management.php?page=' . $returnPage . '&status=delete_error');
            exit;
        }
    }

    $totalEpisodes = (int) $pdo->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEpisodes / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    $episodesListStmt = $pdo->prepare(
        'SELECT id, title, guid, status, pub_date
         FROM episodes
         ORDER BY datetime(pub_date) DESC, id DESC
         LIMIT :limit OFFSET :offset'
    );
    $episodesListStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $episodesListStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $episodesListStmt->execute();
    $episodesList = $episodesListStmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error en episodes_management.php: ' . $e->getMessage() . "\n";
    exit;
}
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
