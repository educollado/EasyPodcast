<?php

declare(strict_types=1);

/**
 * Devuelve las estadísticas básicas del podcast a partir de la BD.
 *
 * @return array{
 *   published: int,
 *   drafts: int,
 *   total: int,
 *   last_title: string,
 *   last_pub_date: string,
 *   audio_size_bytes: int,
 *   error: string
 * }
 */
function loadStatsData(string $dbPath): array
{
    $published       = 0;
    $drafts          = 0;
    $total           = 0;
    $lastTitle       = '';
    $lastPubDate     = '';
    $audioSizeBytes  = 0;
    $error           = '';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Contadores por estado.
        $rows = $pdo->query(
            "SELECT status, COUNT(*) AS cnt FROM episodes GROUP BY status"
        )->fetchAll();
        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            if ($row['status'] === 'published') {
                $published = $cnt;
            } else {
                $drafts += $cnt;
            }
            $total += $cnt;
        }

        // Último episodio publicado.
        $last = $pdo->query(
            "SELECT title, pub_date FROM episodes WHERE status = 'published'
             ORDER BY pub_date DESC LIMIT 1"
        )->fetch();
        if ($last) {
            $lastTitle   = (string) $last['title'];
            $lastPubDate = (string) $last['pub_date'];
        }

        // Tamaño total de audios almacenado en BD.
        $sizeRow = $pdo->query(
            "SELECT COALESCE(SUM(audio_size_bytes), 0) AS total FROM episodes"
        )->fetch();
        $audioSizeBytes = (int) ($sizeRow['total'] ?? 0);

    } catch (Throwable $e) {
        $error = 'Error al cargar estadísticas: ' . $e->getMessage();
    }

    return compact(
        'published', 'drafts', 'total',
        'lastTitle', 'lastPubDate',
        'audioSizeBytes', 'error'
    );
}

/**
 * Formatea bytes en una cadena legible (KB, MB, GB).
 */
function formatBytes(int $bytes): string
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
