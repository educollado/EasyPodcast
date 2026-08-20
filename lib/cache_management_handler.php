<?php

declare(strict_types=1);

require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/i18n.php';

/**
 * Regenera las variantes de imagen para todos los episodios y el podcast.
 * Devuelve el número de imágenes procesadas (origen único, no variantes).
 */
function regenerateAllImages(PDO $pdo): int
{
    $sizes = [80, 144, 220];
    $count = 0;

    // Imagen del podcast
    $podcast = activePodcast($pdo);
    $podcastId = (int) ($podcast['id'] ?? 0);
    if ($podcast && (string) ($podcast['image_url'] ?? '') !== '') {
        foreach ($sizes as $size) {
            ensureSquareImageVariant((string) $podcast['image_url'], $size);
        }
        $count++;
    }

    // Imágenes de episodios (distintas y no vacías)
    $stmt = $pdo->prepare("SELECT DISTINCT image_url FROM episodes WHERE podcast_id = :podcast_id AND image_url IS NOT NULL AND image_url != '' ORDER BY id DESC");
    $stmt->execute([':podcast_id' => $podcastId]);
    while ($row = $stmt->fetch()) {
        $url = (string) ($row['image_url'] ?? '');
        if ($url === '') {
            continue;
        }
        foreach ($sizes as $size) {
            ensureSquareImageVariant($url, $size);
        }
        $count++;
    }

    return $count;
}

/**
 * Carga y procesa los datos del panel de gestión de caché.
 * Maneja tres acciones POST: save_settings, clear_cache, regenerate_images.
 *
 * @return array{cacheEnabled:string, error:string, notice:string}
 */
function loadCacheManagementData(string $dbPath): array
{
    $error        = '';
    $notice       = '';
    $cacheEnabled = '0';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $podcastId = activePodcastId($pdo);
        $existingStmt = $pdo->prepare('SELECT cache_enabled FROM podcast WHERE id = :podcast_id LIMIT 1');
        $existingStmt->execute([':podcast_id' => $podcastId]);
        $existing = $existingStmt->fetch();
        if ($existing) {
            $cacheEnabled = (string) ($existing['cache_enabled'] ?? '0');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = (string) ($_POST['cache_action'] ?? '');

            if ($action === 'save_settings') {
                $newEnabled = isset($_POST['cache_enabled']) ? 1 : 0;
                $stmt = $pdo->prepare('UPDATE podcast SET cache_enabled = :val WHERE id = :podcast_id');
                $stmt->execute([':val' => $newEnabled, ':podcast_id' => $podcastId]);
                $cacheEnabled = (string) $newEnabled;
                // Si se deshabilita la caché, limpiarla para no servir datos huérfanos.
                if (!$newEnabled) {
                    clearWebCache();
                }
                $notice = __('Configuración de caché guardada.');

            } elseif ($action === 'clear_cache') {
                $webOk = clearWebCache();
                if ($webOk) {
                    $notice = __('Caché web borrada correctamente.');
                } else {
                    $error = __('No se pudo borrar la caché web.');
                }

            } elseif ($action === 'regenerate_images') {
                if (!clearImageCache()) {
                    $error = __('No se pudo limpiar la caché de imágenes antes de regenerar.');
                } else {
                    $count  = regenerateAllImages($pdo);
                    $notice = __('Imágenes regeneradas: %d fuente(s) procesada(s).', $count);
                }
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en cache_management.php: ' . $e->getMessage() . "\n";
        exit;
    }

    return compact('cacheEnabled', 'error', 'notice');
}
