<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/sitemap_builder.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/episode_helpers.php';
require_once __DIR__ . '/scheduler.php';

/**
 * Carga los datos del listado de episodios para el panel administrativo.
 * En POST gestiona el borrado con patrón PRG (redirige y termina).
 * En error de BD responde HTTP 500 y termina la ejecución.
 *
 * @return array{searchQuery:string, searchResults:array, draftEpisodes:array, scheduledEpisodes:array, publishedEpisodes:array, draftCurrentPage:int, draftTotalPages:int, totalDrafts:int, totalScheduled:int, currentPage:int, totalPublished:int, totalPages:int, error:string, notice:string}
 */
function loadEpisodesManagementData(string $dbPath, int $requestedPage, int $requestedDraftPage, string $searchQuery = ''): array
{
    $error            = '';
    $notice           = '';
    $perPage          = 10;
    $currentPage      = $requestedPage;
    $totalPublished   = 0;
    $totalPages       = 1;
    $draftCurrentPage = $requestedDraftPage;
    $totalDrafts      = 0;
    $draftTotalPages  = 1;
    $scheduledEpisodes = [];
    $totalScheduled    = 0;
    $searchResults    = [];

    // Mensajes de estado tras redirección PRG.
    $statusParam = (string) ($_GET['status'] ?? '');
    if ($statusParam === 'deleted') {
        $notice = __('Capítulo borrado correctamente.');
    } elseif ($statusParam === 'delete_error') {
        $error = __('No se encontró el capítulo que se intentó borrar.');
    } elseif ($statusParam === 'feed_warning') {
        $notice = __('Capítulo borrado correctamente.') . ' ' . __('(Aviso: no se pudo regenerar feed.xml/sitemap.xml)');
    } elseif ($statusParam === 'cache_warning') {
        $notice = __('Capítulo borrado correctamente.') . ' ' . __('(Aviso: no se pudo limpiar completamente la caché)');
    } elseif ($statusParam === 'feed_cache_warning') {
        $notice = __('Capítulo borrado correctamente.') . ' ' . __('(Aviso: no se pudo regenerar feed.xml/sitemap.xml)') . ' ' . __('(Aviso: no se pudo limpiar completamente la caché)');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $podcastId = activePodcastId($pdo);

        try {
            publishScheduledEpisodesAndRefresh($pdo);
        } catch (Throwable $e) {
            // Silencioso: el panel debe seguir cargando aunque falle el scheduler.
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS episodes (
              id INTEGER PRIMARY KEY,
              guid TEXT NOT NULL UNIQUE,
              title TEXT NOT NULL,
              description TEXT NOT NULL,
              link TEXT,
              pub_date TEXT,
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
                $fetchStmt = $pdo->prepare('SELECT audio_url, image_url FROM episodes WHERE id = :id AND podcast_id = :podcast_id');
                $fetchStmt->execute([':id' => $deleteEpisodeId, ':podcast_id' => $podcastId]);
                $episodeFiles = $fetchStmt->fetch() ?: [];

                $deleteStmt = $pdo->prepare('DELETE FROM episodes WHERE id = :id AND podcast_id = :podcast_id');
                $deleteStmt->execute([':id' => $deleteEpisodeId, ':podcast_id' => $podcastId]);

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
                    if ($imageUrl !== '' && !isImageUrlInUse($pdo, $imageUrl)) {
                        $localImage = resolveLocalImagePathFromUrl($imageUrl);
                        if ($localImage !== null) {
                            @unlink($localImage);
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

        // Búsqueda: si hay query, devuelve resultados y omite las secciones paginadas.
        if ($searchQuery !== '') {
            $searchStmt = $pdo->prepare(
                "SELECT id, title, guid, status, pub_date, link
                 FROM episodes
                 WHERE podcast_id = :podcast_id AND title LIKE :q
                 ORDER BY title ASC
                 LIMIT 100"
            );
            $searchStmt->execute([':podcast_id' => $podcastId, ':q' => '%' . $searchQuery . '%']);
            $searchResults = $searchStmt->fetchAll();

            return compact('searchQuery', 'searchResults', 'draftEpisodes', 'scheduledEpisodes', 'publishedEpisodes', 'draftCurrentPage', 'draftTotalPages', 'totalDrafts', 'totalScheduled', 'currentPage', 'totalPublished', 'totalPages', 'error', 'notice');
        }

        // Borradores: paginados.
        $countDrafts = $pdo->prepare("SELECT COUNT(*) FROM episodes WHERE podcast_id = :podcast_id AND status = 'draft'");
        $countDrafts->execute([':podcast_id' => $podcastId]);
        $totalDrafts = (int) $countDrafts->fetchColumn();
        $draftTotalPages = max(1, (int) ceil($totalDrafts / $perPage));
        if ($draftCurrentPage > $draftTotalPages) {
            $draftCurrentPage = $draftTotalPages;
        }
        $draftOffset = ($draftCurrentPage - 1) * $perPage;

        $draftStmt = $pdo->prepare(
            'SELECT id, title, guid, status, pub_date, link
             FROM episodes
             WHERE podcast_id = :podcast_id AND status = \'draft\'
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset'
        );
        $draftStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $draftStmt->bindValue(':offset', $draftOffset, PDO::PARAM_INT);
        $draftStmt->bindValue(':podcast_id', $podcastId, PDO::PARAM_INT);
        $draftStmt->execute();
        $draftEpisodes = $draftStmt->fetchAll();

        // Programados: sin paginación (suelen ser pocos), ordenados por fecha de publicación ascendente.
        $countScheduled = $pdo->prepare("SELECT COUNT(*) FROM episodes WHERE podcast_id = :podcast_id AND status = 'scheduled'");
        $countScheduled->execute([':podcast_id' => $podcastId]);
        $totalScheduled = (int) $countScheduled->fetchColumn();
        $scheduledStmt = $pdo->prepare(
            "SELECT id, title, guid, status, pub_date, link
             FROM episodes
             WHERE podcast_id = :podcast_id AND status = 'scheduled'
             ORDER BY datetime(pub_date) ASC"
        );
        $scheduledStmt->execute([':podcast_id' => $podcastId]);
        $scheduledEpisodes = $scheduledStmt->fetchAll();

        // Publicados: paginados.
        $countPublished = $pdo->prepare("SELECT COUNT(*) FROM episodes WHERE podcast_id = :podcast_id AND status = 'published'");
        $countPublished->execute([':podcast_id' => $podcastId]);
        $totalPublished = (int) $countPublished->fetchColumn();
        $totalPages     = max(1, (int) ceil($totalPublished / $perPage));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
        $offset = ($currentPage - 1) * $perPage;

        $publishedStmt = $pdo->prepare(
            'SELECT id, title, guid, status, pub_date, link
             FROM episodes
             WHERE podcast_id = :podcast_id AND status = \'published\'
             ORDER BY datetime(pub_date) DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $publishedStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $publishedStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $publishedStmt->bindValue(':podcast_id', $podcastId, PDO::PARAM_INT);
        $publishedStmt->execute();
        $publishedEpisodes = $publishedStmt->fetchAll();
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en episodes_management.php: ' . $e->getMessage() . "\n";
        exit;
    }

    return compact('searchQuery', 'searchResults', 'draftEpisodes', 'scheduledEpisodes', 'publishedEpisodes', 'draftCurrentPage', 'draftTotalPages', 'totalDrafts', 'totalScheduled', 'currentPage', 'totalPublished', 'totalPages', 'error', 'notice');
}
