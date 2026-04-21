<?php

declare(strict_types=1);

/**
 * Registra una accion (descarga o reproduccion) de episodio en la base de datos.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @param int $episodeId ID del episodio
 * @param string $episodeGuid GUID del episodio
 * @param string $episodeTitle Título del episodio
 * @param string $ipAddress IP del visitante
 * @param string $actionType Tipo de accion: 'download' o 'play'
 * @param string|null $userAgent User-Agent del cliente
 * @param string|null $referer URL de referencia
 * @return bool True si se registró correctamente
 */
function registerDownload(
    PDO $pdo,
    int $episodeId,
    string $episodeGuid,
    string $episodeTitle,
    string $ipAddress,
    string $actionType = 'download',
    ?string $userAgent = null,
    ?string $referer = null
): bool {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO estadisticas (episode_id, episode_guid, episode_title, ip_address, action_type, user_agent, referer, download_date)
             VALUES (:episode_id, :episode_guid, :episode_title, :ip_address, :action_type, :user_agent, :referer, datetime('now'))"
        );
        
        $stmt->execute([
            ':episode_id' => $episodeId,
            ':episode_guid' => $episodeGuid,
            ':episode_title' => $episodeTitle,
            ':ip_address' => $ipAddress,
            ':action_type' => $actionType,
            ':user_agent' => $userAgent,
            ':referer' => $referer,
        ]);

        // Limpiar datos brutos de hace más de 7 días
        $pdo->exec("DELETE FROM estadisticas WHERE download_date < datetime('now', '-7 days')");

        return true;
    } catch (Throwable $e) {
        error_log("Error al registrar accion: " . $e->getMessage());
        return false;
    }
}

/**
 * Registra una reproduccion de episodio.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @param int $episodeId ID del episodio
 * @return bool True si se registró correctamente
 */
function registerPlay(PDO $pdo, int $episodeId): bool
{
    try {
        // Obtener info del episodio
        $stmt = $pdo->prepare("SELECT id, guid, title FROM episodes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $episodeId]);
        $episode = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$episode) {
            return false;
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        return registerDownload(
            $pdo,
            (int) $episode['id'],
            (string) $episode['guid'],
            (string) $episode['title'],
            $ipAddress,
            'play',
            $userAgent,
            $referer
        );
    } catch (Throwable $e) {
        error_log("Error al registrar reproduccion: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene la información de un episodio por su ID.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @param int $episodeId ID del episodio
 * @return array{id:int, guid:string, title:string}|null
 */
function getEpisodeInfo(PDO $pdo, int $episodeId): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT id, guid, title FROM episodes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $episodeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return [
                'id' => (int) $result['id'],
                'guid' => (string) $result['guid'],
                'title' => (string) $result['title'],
            ];
        }
        return null;
    } catch (Throwable $e) {
        error_log("Error al obtener info de episodio: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtiene la URL de audio de un episodio.
 *
 * @param PDO $pdo Conexión a la base de datos
 * @param int $episodeId ID del episodio
 * @return string|null
 */
function getEpisodeAudioUrl(PDO $pdo, int $episodeId): ?string
{
    try {
        $stmt = $pdo->prepare("SELECT audio_url FROM episodes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $episodeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (string) $result['audio_url'] : null;
    } catch (Throwable $e) {
        error_log("Error al obtener audio_url: " . $e->getMessage());
        return null;
    }
}
