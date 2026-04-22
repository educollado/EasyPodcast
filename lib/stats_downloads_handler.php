<?php

declare(strict_types=1);

/**
 * Obtiene estadísticas diarias de descargas (últimos 7 días).
 *
 * @param PDO $pdo Conexión a la base de datos
 * @return array Array de estadísticas diarias
 */
function getDailyStats(PDO $pdo): array
{
    try {
        // Verificar si la columna action_type existe
        $columns = array_column(
            $pdo->query('PRAGMA table_info(estadisticas)')->fetchAll(),
            'name'
        );
        $hasActionType = in_array('action_type', $columns, true);
        
        $sql = "SELECT 
            id, episode_id, episode_title, episode_guid,
            ip_address, user_agent, referer,
            " . ($hasActionType ? 'action_type,' : "'download' as action_type,") . 
            " download_date, datetime(download_date) as formatted_date
         FROM estadisticas
         ORDER BY download_date DESC";
        
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error al obtener estadísticas diarias: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene estadísticas mensuales de descargas.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @param int|null $year Año sido filtro (null = todos)
 * @return array Array de estadísticas mensuales
 */
function getMonthlyStats(PDO $pdo, ?int $year = null): array
{
    try {
        $sql = "SELECT * FROM estadisticas_mensuales";
        $params = [];
        
        if ($year !== null) {
            $sql .= " WHERE anio = :year";
            $params[':year'] = $year;
        }
        
        $sql .= " ORDER BY anio DESC, mes DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error al obtener estadísticas mensuales: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene estadísticas anuales de descargas.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @return array Array de estadísticas anuales
 */
function getYearlyStats(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            "SELECT * FROM estadisticas_anuales ORDER BY anio DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error al obtener estadísticas anuales: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene el total de descargas por episodio.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @return array Array con episode_id, episode_title, episode_guid y total de descargas
 */
function getTotalDownloadsByEpisode(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            "SELECT 
                e.id AS episode_id,
                e.title AS episode_title,
                e.guid AS episode_guid,
                COALESCE(SUM(em.descargas), 0) as total_downloads
             FROM episodes e
             LEFT JOIN estadisticas_mensuales em ON em.episode_id = e.id
             GROUP BY e.id, e.title, e.guid
             ORDER BY total_downloads DESC, e.title ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error al obtener total de descargas: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene los años disponibles en las estadísticas.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @return array Array de años
 */
function getAvailableYears(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            "SELECT DISTINCT anio FROM estadisticas_anuales ORDER BY anio DESC"
        );
        $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $years);
    } catch (Throwable $e) {
        error_log("Error al obtener años disponibles: " . $e->getMessage());
        return [];
    }
}

/**
 * Devuelve todas las colecciones de estadísticas de descargas tal como las usa stats.php.
 *
 * @return array{
 *   filter_year: int|null,
 *   available_years: array<int, int>,
 *   daily: array{items: array<int, array<string, mixed>>, total: int},
 *   monthly: array{items: array<int, array<string, mixed>>, total: int},
 *   yearly: array{items: array<int, array<string, mixed>>, total: int},
 *   summary: array{items: array<int, array<string, mixed>>, total: int}
 * }
 */
function getDownloadsStatsData(PDO $pdo, ?int $filterYear = null): array
{
    $normalizedFilterYear = ($filterYear !== null && $filterYear > 0) ? $filterYear : null;

    $dailyStats = array_map(static function (array $stat): array {
        $downloadDate = (string) ($stat['download_date'] ?? '');
        $actionType = (string) ($stat['action_type'] ?? 'download');
        $stat['display_date'] = formatStatsDate($downloadDate);
        $stat['action_type'] = $actionType;
        $stat['action_type_label'] = getActionTypeLabel($actionType);
        return $stat;
    }, getDailyStats($pdo));

    $monthlyStats = array_map(static function (array $stat): array {
        $stat['period_label'] = formatMonthYear((int) ($stat['anio'] ?? 0), (int) ($stat['mes'] ?? 0));
        return $stat;
    }, getMonthlyStats($pdo, $normalizedFilterYear));

    $yearlyStats = getYearlyStats($pdo);

    $summaryStats = array_values(array_filter(
        getTotalDownloadsByEpisode($pdo),
        static function (array $stat): bool {
            return (int) ($stat['total_downloads'] ?? 0) > 0;
        }
    ));

    $availableYears = getAvailableYears($pdo);

    return [
        'filter_year' => $normalizedFilterYear,
        'available_years' => $availableYears,
        'daily' => [
            'items' => $dailyStats,
            'total' => count($dailyStats),
        ],
        'monthly' => [
            'items' => $monthlyStats,
            'total' => count($monthlyStats),
        ],
        'yearly' => [
            'items' => $yearlyStats,
            'total' => count($yearlyStats),
        ],
        'summary' => [
            'items' => $summaryStats,
            'total' => count($summaryStats),
        ],
    ];
}

/**
 * Devuelve un número de página válido a partir de parámetros GET/POST.
 *
 * @param string $param Nombre del parámetro
 * @param array<string, mixed> $source Origen de parámetros
 * @return int Página normalizada (mínimo 1)
 */
function getStatsPageNumber(string $param, array $source): int
{
    $raw = $source[$param] ?? 1;

    if (is_int($raw)) {
        return max(1, $raw);
    }

    if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
        return max(1, (int) $raw);
    }

    return 1;
}

/**
 * Pagina un conjunto de filas y devuelve metadatos listos para la vista.
 *
 * @param array<int, array<string, mixed>> $rows Filas completas
 * @param int $page Página solicitada (1-based)
 * @param int $perPage Filas por página
 * @return array{
 *   rows: array<int, array<string, mixed>>,
 *   page: int,
 *   per_page: int,
 *   total_rows: int,
 *   total_pages: int,
 *   from: int,
 *   to: int
 * }
 */
function paginateStatsRows(array $rows, int $page, int $perPage = 100): array
{
    $perPage = max(1, $perPage);
    $totalRows = count($rows);

    if ($totalRows === 0) {
        return [
            'rows' => [],
            'page' => 1,
            'per_page' => $perPage,
            'total_rows' => 0,
            'total_pages' => 0,
            'from' => 0,
            'to' => 0,
        ];
    }

    $totalPages = (int) ceil($totalRows / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $pageRows = array_slice($rows, $offset, $perPage);

    return [
        'rows' => $pageRows,
        'page' => $page,
        'per_page' => $perPage,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'from' => $offset + 1,
        'to' => $offset + count($pageRows),
    ];
}

/**
 * Formatea una fecha para mostrar en la interfaz.
 *
 * @param string $dateString Fecha en formato ISO
 * @return string Fecha formateada
 */
function formatStatsDate(string $dateString): string
{
    $ts = strtotime($dateString);
    if ($ts === false) {
        return $dateString;
    }
    return date('d/m/Y H:i:s', $ts);
}

/**
 * Formatea un mes/año para mostrar.
 *
 * @param int $year Año
 * @param int $month Mes (1-12)
 * @return string Mes formateado
 */
function formatMonthYear(int $year, int $month): string
{
    $months = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
    ];
    $monthName = $months[$month] ?? 'Mes ' . $month;
    return $monthName . ' ' . $year;
}

/**
 * Obtiene la etiqueta localizada para el tipo de acción (descarga/reproducción).
 *
 * @param string $actionType Tipo de acción ('play', 'download' o 'feed')
 * @return string Etiqueta localizada
 */
function getActionTypeLabel(string $actionType): string
{
    if ($actionType === 'play') {
        return __('Reproducción');
    }

    if ($actionType === 'feed') {
        return __('Feed');
    }

    return __('Descarga');
}
