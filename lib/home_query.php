<?php

declare(strict_types=1);

// Carga datos de portada: podcast, episodios paginados y metadatos de paginación.
function loadHomeData(string $dbPath, int $requestedPage): array
{
    $podcast = null;
    $episodes = [];
    $error = '';
    $page = $requestedPage;
    $perPage = 20;
    $totalEpisodes = 0;
    $totalPages = 1;

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

        // Calcula paginación total antes de consultar la página actual.
        $totalEpisodes = (int) $pdo
            ->query("SELECT COUNT(*) FROM episodes WHERE status = 'published'")
            ->fetchColumn();
        $totalPages = max(1, (int) ceil($totalEpisodes / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // Recupera solo episodios de la página actual (solo publicados).
        $stmt = $pdo->prepare(
            "SELECT id, title, description, link, pub_date, audio_url, duration, image_url
             FROM episodes
             WHERE status = 'published'
             ORDER BY datetime(pub_date) DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $episodes = $stmt->fetchAll();
    } catch (Throwable $e) {
        $error = 'No se pudo cargar la portada: ' . $e->getMessage();
    }

    return compact('podcast', 'episodes', 'page', 'perPage', 'totalEpisodes', 'totalPages', 'error');
}
