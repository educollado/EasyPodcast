<?php

declare(strict_types=1);

require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/public_episode_helpers.php';

/**
 * Carga el podcast y el episodio resolviendo la URL amigable /YYYY/MM/slug.
 * Devuelve httpStatus 404 si el episodio no existe o los parámetros son inválidos,
 * y 500 en caso de error de BD. El dispatcher debe llamar a http_response_code().
 * Si $allowDraft es true también busca entre episodios en estado 'draft' (solo para admin).
 *
 * @return array{podcast:?array, episode:?array, error:string, httpStatus:int}
 */
function loadEpisodeData(string $dbPath, string $year, string $month, string $slug, bool $allowDraft = false): array
{
    $podcast = null;
    $episode = null;
    $error = '';
    $httpStatus = 200;

    // Valida parámetros de ruta al inicio para devolver 404 consistente.
    if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        return ['podcast' => null, 'episode' => null, 'error' => 'Capítulo no encontrado.', 'httpStatus' => 404];
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $podcast = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: null;

        // Filtra por año/mes de URL y resuelve el episodio exacto por slug.
        // Con $allowDraft también se incluyen borradores (solo para previsualización de admin).
        $statusClause = $allowDraft
            ? "status IN ('published', 'draft')"
            : "status = 'published'";
        $stmt = $pdo->prepare(
            "SELECT *
             FROM episodes
             WHERE $statusClause
               AND strftime('%Y', pub_date) = :year
               AND strftime('%m', pub_date) = :month
             ORDER BY datetime(pub_date) DESC, id DESC"
        );
        $stmt->execute([':year' => $year, ':month' => $month]);
        $rows = $stmt->fetchAll();

        // Varios episodios pueden compartir mes/año. El slug resuelve el definitivo.
        foreach ($rows as $row) {
            $rowSlug = slugFromEpisodeLink((string) ($row['link'] ?? ''));
            if ($rowSlug === null) {
                $rowSlug = slugify((string) ($row['title'] ?? ''));
            }

            if ($rowSlug === $slug) {
                $episode = $row;
                break;
            }
        }

        if (!$episode) {
            $error = 'Capítulo no encontrado.';
            $httpStatus = 404;
        }
    } catch (Throwable $e) {
        $error = 'No se pudo cargar el capítulo: ' . $e->getMessage();
        $httpStatus = 500;
    }

    return compact('podcast', 'episode', 'error', 'httpStatus');
}
