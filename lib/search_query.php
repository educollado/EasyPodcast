<?php

declare(strict_types=1);

// Escapa caracteres especiales de LIKE en SQLite.
function escapeSqlLike(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

// Carga datos de búsqueda: podcast, episodios coincidentes y metadatos de paginación.
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

        // La app está diseñada alrededor de una única fila de podcast.
        $podcast = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: null;
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
                 WHERE status = 'published'
                   AND (title LIKE :term ESCAPE '\\' OR description LIKE :term ESCAPE '\\')"
            );
            $countStmt->execute([':term' => $term]);
            $totalEpisodes = (int) $countStmt->fetchColumn();

            $totalPages = max(1, (int) ceil($totalEpisodes / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;

            // Recupera solo los episodios de la página actual.
            $episodesStmt = $pdo->prepare(
                "SELECT id, title, description, link, pub_date, audio_url, duration, image_url
                 FROM episodes
                 WHERE status = 'published'
                   AND (title LIKE :term ESCAPE '\\' OR description LIKE :term ESCAPE '\\')
                 ORDER BY datetime(pub_date) DESC, id DESC
                 LIMIT :limit OFFSET :offset"
            );
            $episodesStmt->bindValue(':term', $term, PDO::PARAM_STR);
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
