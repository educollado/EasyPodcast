<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/sitemap_builder.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/episode_helpers.php';

/**
 * Carga los datos del listado de episodios para el panel administrativo.
 * En POST gestiona el borrado con patrón PRG (redirige y termina).
 * En error de BD responde HTTP 500 y termina la ejecución.
 *
 * @return array{episodesList:array, currentPage:int, totalEpisodes:int, totalPages:int, error:string, notice:string}
 */
function loadEpisodesManagementData(string $dbPath, int $requestedPage): array
{
    $error        = '';
    $notice       = '';
    $episodesList = [];
    $perPage      = 50;
    $currentPage  = $requestedPage;
    $totalEpisodes = 0;
    $totalPages   = 1;

    // Mensajes de estado tras redirección PRG.
    $statusParam = (string) ($_GET['status'] ?? '');
    if ($statusParam === 'deleted') {
        $notice = 'Capítulo borrado correctamente.';
    } elseif ($statusParam === 'delete_error') {
        $error = 'No se encontró el capítulo que se intentó borrar.';
    } elseif ($statusParam === 'feed_warning') {
        $notice = 'Capítulo borrado correctamente. (Aviso: no se pudo regenerar feed.xml/sitemap.xml)';
    } elseif ($statusParam === 'cache_warning') {
        $notice = 'Capítulo borrado correctamente. (Aviso: no se pudo limpiar completamente la caché)';
    } elseif ($statusParam === 'feed_cache_warning') {
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
            // Borrado con patrón PRG para evitar reenvío de formulario.
            $deleteEpisodeId = (int) ($_POST['delete_episode_id'] ?? 0);
            $returnPage      = max(1, (int) ($_POST['return_page'] ?? 1));

            if ($deleteEpisodeId > 0) {
                // Guardar URLs de archivos antes de borrar el registro.
                $fetchStmt = $pdo->prepare('SELECT audio_url, image_url FROM episodes WHERE id = :id');
                $fetchStmt->execute([':id' => $deleteEpisodeId]);
                $episodeFiles = $fetchStmt->fetch() ?: [];

                $deleteStmt = $pdo->prepare('DELETE FROM episodes WHERE id = :id');
                $deleteStmt->execute([':id' => $deleteEpisodeId]);

                if ($deleteStmt->rowCount() > 0) {
                    // Eliminar archivos huérfanos si ningún otro episodio los usa.
                    $audioUrl = (string) ($episodeFiles['audio_url'] ?? '');
                    if ($audioUrl !== '') {
                        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE audio_url = :url');
                        $cntStmt->execute([':url' => $audioUrl]);
                        if ((int) $cntStmt->fetchColumn() === 0) {
                            $localAudio = resolveLocalAudioPathFromUrl($audioUrl);
                            if ($localAudio !== null) {
                                @unlink($localAudio);
                            }
                        }
                    }
                    $imageUrl = (string) ($episodeFiles['image_url'] ?? '');
                    if ($imageUrl !== '') {
                        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE image_url = :url');
                        $cntStmt->execute([':url' => $imageUrl]);
                        if ((int) $cntStmt->fetchColumn() === 0) {
                            $localImage = resolveLocalImagePathFromUrl($imageUrl);
                            if ($localImage !== null) {
                                @unlink($localImage);
                            }
                        }
                    }

                    $status = 'deleted';
                    $feedOk = true;
                    try {
                        writePodcastFeedFile($pdo, __DIR__ . '/../feed.xml', resolveFeedSelfHref($pdo));
                        writePodcastSitemapFile($pdo, __DIR__ . '/../sitemap.xml');
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

        // Carga paginada de todos los episodios (publicados y borradores).
        $totalEpisodes = (int) $pdo->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
        $totalPages    = max(1, (int) ceil($totalEpisodes / $perPage));
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

    return compact('episodesList', 'currentPage', 'totalEpisodes', 'totalPages', 'error', 'notice');
}
