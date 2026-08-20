<?php

declare(strict_types=1);

require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/i18n.php';

/**
 * Devuelve el resumen general de episodios para la pantalla de estadísticas.
 *
 * @return array{
 *   published: int,
 *   drafts: int,
 *   total: int,
 *   lastTitle: string,
 *   lastPubDate: string,
 *   audioSizeBytes: int
 * }
 */
function getStatsOverview(PDO $pdo): array
{
    $published = 0;
    $drafts = 0;
    $total = 0;
    $lastTitle = '';
    $lastPubDate = '';
    $audioSizeBytes = 0;

    $podcastId = activePodcastId($pdo);
    $rowsStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM episodes WHERE podcast_id = :podcast_id GROUP BY status");
    $rowsStmt->execute([':podcast_id' => $podcastId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $cnt = (int) $row['cnt'];
        if (($row['status'] ?? '') === 'published') {
            $published = $cnt;
        } else {
            $drafts += $cnt;
        }
        $total += $cnt;
    }

    $lastStmt = $pdo->prepare("SELECT title, pub_date FROM episodes WHERE podcast_id = :podcast_id AND status = 'published' ORDER BY pub_date DESC LIMIT 1");
    $lastStmt->execute([':podcast_id' => $podcastId]);
    $last = $lastStmt->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $lastTitle = (string) ($last['title'] ?? '');
        $lastPubDate = (string) ($last['pub_date'] ?? '');
    }

    $sizeStmt = $pdo->prepare("SELECT COALESCE(SUM(audio_size_bytes), 0) AS total FROM episodes WHERE podcast_id = :podcast_id");
    $sizeStmt->execute([':podcast_id' => $podcastId]);
    $sizeRow = $sizeStmt->fetch(PDO::FETCH_ASSOC);
    $audioSizeBytes = (int) ($sizeRow['total'] ?? 0);

    return compact(
        'published',
        'drafts',
        'total',
        'lastTitle',
        'lastPubDate',
        'audioSizeBytes'
    );
}

/**
 * Devuelve el estado actual de la caché web.
 *
 * @return array{
 *   cacheEnabled: bool,
 *   cacheFiles: int,
 *   cacheSizeBytes: int
 * }
 */
function getCacheStatsData(string $dbPath, ?string $cacheDir = null): array
{
    $cacheEnabled = isWebCacheEnabled($dbPath);
    $cacheFiles = 0;
    $cacheSizeBytes = 0;
    $cacheDir = $cacheDir ?? cacheDirectoryPath();

    if (is_dir($cacheDir)) {
        $entries = @scandir($cacheDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $cacheDir . '/' . $entry;
            if (is_file($path)) {
                $cacheFiles++;
                $cacheSizeBytes += (int) @filesize($path);
            }
        }
    }

    return compact('cacheEnabled', 'cacheFiles', 'cacheSizeBytes');
}

/**
 * Devuelve las estadísticas básicas del podcast a partir de la BD y del sistema de caché.
 *
 * @return array{
 *   published: int,
 *   drafts: int,
 *   total: int,
 *   lastTitle: string,
 *   lastPubDate: string,
 *   audioSizeBytes: int,
 *   cacheEnabled: bool,
 *   cacheFiles: int,
 *   cacheSizeBytes: int,
 *   error: string
 * }
 */
function loadStatsData(string $dbPath): array
{
    $published = 0;
    $drafts = 0;
    $total = 0;
    $lastTitle = '';
    $lastPubDate = '';
    $audioSizeBytes = 0;
    $cacheEnabled = false;
    $cacheFiles = 0;
    $cacheSizeBytes = 0;
    $error = '';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        extract(getStatsOverview($pdo), EXTR_OVERWRITE);
    } catch (Throwable $e) {
        $error = __('Error al cargar estadísticas: %s', $e->getMessage());
    }

    extract(getCacheStatsData($dbPath), EXTR_OVERWRITE);

    return compact(
        'published', 'drafts', 'total',
        'lastTitle', 'lastPubDate',
        'audioSizeBytes',
        'cacheEnabled', 'cacheFiles', 'cacheSizeBytes',
        'error'
    );
}

/**
 * Formatea bytes en una cadena legible (KB, MB, GB).
 */
function statsFormatBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    $val = $bytes / (1024 ** $i);
    return ($i === 0 ? (string) $val : number_format($val, 1)) . ' ' . $units[$i];
}
