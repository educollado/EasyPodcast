<?php

declare(strict_types=1);

require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/public_episode_helpers.php';

/**
 * Intenta extraer {year, month, slug} de un enlace en formato /YYYY/MM/slug.
 *
 * @return ?array{year:string, month:string, slug:string}
 */
function extractEpisodeRouteFromLink(?string $link): ?array
{
    $raw = trim((string) $link);
    if ($raw === '') {
        return null;
    }

    $path = (string) parse_url($raw, PHP_URL_PATH);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^/([0-9]{4})/([0-9]{2})/([a-z0-9-]+)/?$#', $path, $matches) !== 1) {
        return null;
    }

    return [
        'year'  => $matches[1],
        'month' => $matches[2],
        'slug'  => $matches[3],
    ];
}

/**
 * Comprueba si una fila de episodio corresponde a la ruta /YYYY/MM/slug solicitada.
 *
 * Regla:
 * - Si el enlace guardado contiene una ruta válida /YYYY/MM/slug, se usa como fuente de verdad.
 * - Si no hay enlace válido, se usa fallback por pub_date + slug del título.
 */
function episodeMatchesRoute(array $row, string $year, string $month, string $slug): bool
{
    $routeFromLink = extractEpisodeRouteFromLink((string) ($row['link'] ?? ''));
    if ($routeFromLink !== null) {
        return $routeFromLink['year'] === $year
            && $routeFromLink['month'] === $month
            && $routeFromLink['slug'] === $slug;
    }

    $pubDate = trim((string) ($row['pub_date'] ?? ''));
    if ($pubDate === '') {
        return false;
    }

    $ts = strtotime($pubDate);
    if ($ts === false) {
        return false;
    }

    if (date('Y', $ts) !== $year || date('m', $ts) !== $month) {
        return false;
    }

    return slugify((string) ($row['title'] ?? '')) === $slug;
}

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

        // Recupera episodios según estado y resuelve el episodio exacto por la ruta solicitada.
        // Con $allowDraft también se incluyen borradores (solo para previsualización de admin).
        // No se filtra por pub_date en SQL para no romper URLs cuando el link guardado
        // se conserva aunque cambie la fecha de publicación.
        $statusClause = $allowDraft
            ? "status IN ('published', 'draft')"
            : "status = 'published'";
        $stmt = $pdo->prepare(
            "SELECT *
             FROM episodes
             WHERE $statusClause
             ORDER BY datetime(pub_date) DESC, id DESC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Varios episodios pueden compartir ruta parcial. Resolvemos el definitivo por URL completa.
        foreach ($rows as $row) {
            if (episodeMatchesRoute($row, $year, $month, $slug)) {
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
