<?php

declare(strict_types=1);

require_once __DIR__ . '/scheduler.php';

/**
 * Escapa los caracteres especiales de LIKE en SQLite (\, %, _).
 * Necesario para búsquedas de texto libre sin inyección de wildcards.
 */
function escapeSqlLike(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

/**
 * Carga los datos de búsqueda: podcast, episodios que coinciden con la query y paginación.
 * Si $query está vacía devuelve listas vacías sin ejecutar ninguna consulta de episodios.
 *
 * @return array{podcast:?array, episodes:array, page:int, perPage:int, totalEpisodes:int, totalPages:int, error:string}
 */
function loadSearchData(string $dbPath, string $query, int $requestedPage): array
{
    $podcast      = null;
    $episodes     = [];
    $error        = '';
    $page         = $requestedPage;
    $perPage      = 20;
    $totalEpisodes = 0;
    $totalPages   = 1;

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        try {
            publishScheduledEpisodesAndRefresh($pdo);
        } catch (Throwable $e) {
            // Silencioso: la búsqueda debe seguir funcionando aunque falle el scheduler.
        }

        // La app está diseñada alrededor de una única fila de podcast.
        $podcast = activePodcast($pdo);
        $podcastId = (int) ($podcast['id'] ?? 0);
        $configuredPerPage = (int) (($podcast['home_items_per_page'] ?? null) ?? 20);
        if ($configuredPerPage >= 1) {
            $perPage = $configuredPerPage;
        }

        if ($query !== '') {
            $term = '%' . escapeSqlLike($query) . '%';

            // Cuenta total de resultados antes de paginar.
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM episodes
                 WHERE podcast_id = :podcast_id AND status = 'published'
                   AND (title LIKE :term ESCAPE '\\' OR content LIKE :term ESCAPE '\\')"
            );
            $countStmt->execute([':term' => $term, ':podcast_id' => $podcastId]);
            $totalEpisodes = (int) $countStmt->fetchColumn();

            $totalPages = max(1, (int) ceil($totalEpisodes / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;

            // Recupera solo los episodios de la página actual.
            $episodesStmt = $pdo->prepare(
                "SELECT id, title, content, short_description, link, pub_date, audio_url, duration, image_url
                 FROM episodes
                 WHERE podcast_id = :podcast_id AND status = 'published'
                   AND (title LIKE :term ESCAPE '\\' OR content LIKE :term ESCAPE '\\')
                 ORDER BY datetime(pub_date) DESC, id DESC
                 LIMIT :limit OFFSET :offset"
            );
            $episodesStmt->bindValue(':term', $term, PDO::PARAM_STR);
            $episodesStmt->bindValue(':podcast_id', $podcastId, PDO::PARAM_INT);
            $episodesStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $episodesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $episodesStmt->execute();
            $episodes = $episodesStmt->fetchAll();
        }
    } catch (Throwable $e) {
        $error = 'No se pudo realizar la búsqueda: ' . $e->getMessage();
    }

    return compact('podcast', 'episodes', 'page', 'perPage', 'totalEpisodes', 'totalPages', 'error');
}
