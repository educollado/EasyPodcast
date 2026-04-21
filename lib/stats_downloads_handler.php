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
        $stmt = $pdo->query(
            "SELECT 
                id, episode_id, episode_title, episode_guid,
                ip_address, user_agent, referer,
                download_date, datetime(download_date) as formatted_date
             FROM estadisticas
             ORDER BY download_date DESC"
        );
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
 * @return array Array con episode_id, episode_title y total de descargas
 */
function getTotalDownloadsByEpisode(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            "SELECT 
                episode_id, 
                episode_title,
                episode_guid,
                COALESCE((SELECT SUM(descargas) FROM estadisticas_mensuales WHERE episode_id = e.episode_id), 0) as total_downloads
             FROM episodes e
             ORDER BY total_downloads DESC, episode_title ASC"
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
