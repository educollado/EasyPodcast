<?php

declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/i18n.php';

/**
 * Convierte bytes a una cadena legible (KB / MB).
 */
function mediaCleanupFormatBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    return number_format($bytes / 1024, 2) . ' KB';
}

/**
 * Extrae los basenames de imágenes locales referenciadas en contenido HTML.
 * Busca atributos src que apunten a /images/.
 */
function extractImageBasenamesFromHtml(string $html): array
{
    $basenames = [];
    // Captura rutas como /images/foto.jpg dentro de src="..." o src='...'
    if (preg_match_all('#/images/([^"\'<>\s?#]+)#', $html, $matches)) {
        foreach ($matches[1] as $filename) {
            $basenames[] = basename($filename);
        }
    }
    return $basenames;
}

/**
 * Detecta (y opcionalmente borra) archivos huérfanos en audios/ e images/.
 *
 * @return array{
 *   orphanAudios: array<string, int>,
 *   orphanImages: array<string, int>,
 *   error: string,
 *   notice: string
 * }
 */
function loadMediaCleanupData(string $dbPath, string $projectDir): array
{
    $orphanAudios = [];
    $orphanImages = [];
    $error        = '';
    $notice       = '';

    // --- Borrado POST ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();

        $files        = (array) ($_POST['files'] ?? []);
        $deleted      = 0;
        $freedBytes   = 0;

        $audiosDir = realpath($projectDir . '/audios');
        $imagesDir = realpath($projectDir . '/images');

        foreach ($files as $item) {
            // Formato esperado: "audio:filename.mp3" o "image:filename.jpg"
            if (!is_string($item) || !str_contains($item, ':')) {
                continue;
            }
            [$type, $filename] = explode(':', $item, 2);

            // Solo nombres de archivo simples (sin rutas)
            if ($filename !== basename($filename) || $filename === '') {
                continue;
            }

            if ($type === 'audio' && $audiosDir !== false) {
                $path = realpath($audiosDir . '/' . $filename);
                if ($path !== false && str_starts_with($path, $audiosDir . '/') && is_file($path)) {
                    $freedBytes += (int) filesize($path);
                    unlink($path);
                    $deleted++;
                }
            } elseif ($type === 'image' && $imagesDir !== false) {
                $path = realpath($imagesDir . '/' . $filename);
                if ($path !== false && str_starts_with($path, $imagesDir . '/') && is_file($path)) {
                    $freedBytes += (int) filesize($path);
                    unlink($path);
                    $deleted++;
                }
            }
        }

        header('Location: media_cleanup.php?deleted=' . $deleted . '&freed=' . $freedBytes);
        exit;
    }

    // --- Carga de datos ---
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $usedAudios = [];
        $usedImages = [];

        // Audios usados en episodios (acepta URLs relativas /audios/... y absolutas https://.../audios/...)
        $rows = $pdo->query('SELECT audio_url FROM episodes')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $url) {
            if (is_string($url) && str_contains($url, '/audios/')) {
                $usedAudios[] = basename($url);
            }
        }

        // Imágenes usadas en episodios (acepta URLs relativas y absolutas)
        $rows = $pdo->query("SELECT image_url FROM episodes WHERE image_url != ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $url) {
            if (is_string($url) && str_contains($url, '/images/')) {
                $usedImages[] = basename($url);
            }
        }

        // Imagen de portada del canal
        $podcastImage = $pdo->query("SELECT image_url FROM podcast WHERE image_url != '' LIMIT 1")->fetchColumn();
        if (is_string($podcastImage) && str_contains($podcastImage, '/images/')) {
            $usedImages[] = basename($podcastImage);
        }

        // Imágenes embebidas en el contenido HTML de las páginas
        $pageContents = $pdo->query('SELECT content FROM pages')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pageContents as $html) {
            if (is_string($html) && $html !== '') {
                foreach (extractImageBasenamesFromHtml($html) as $basename) {
                    $usedImages[] = $basename;
                }
            }
        }

        $usedAudios = array_unique($usedAudios);
        $usedImages = array_unique($usedImages);

        // Escanear disco
        $audiosDir = $projectDir . '/audios';
        $imagesDir = $projectDir . '/images';

        $skipFiles = ['.', '..', '.htaccess'];

        if (is_dir($audiosDir)) {
            $diskAudios = array_diff((array) scandir($audiosDir), $skipFiles);
            foreach (array_diff($diskAudios, $usedAudios) as $file) {
                $fullPath = $audiosDir . '/' . $file;
                if (is_file($fullPath)) {
                    $orphanAudios[$file] = (int) filesize($fullPath);
                }
            }
        }

        if (is_dir($imagesDir)) {
            $diskImages = array_diff((array) scandir($imagesDir), $skipFiles);
            foreach (array_diff($diskImages, $usedImages) as $file) {
                $fullPath = $imagesDir . '/' . $file;
                if (is_file($fullPath)) {
                    $orphanImages[$file] = (int) filesize($fullPath);
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    // Mensaje de confirmación tras borrado (PRG)
    if (isset($_GET['deleted'])) {
        $n = (int) $_GET['deleted'];
        $freed = (int) ($_GET['freed'] ?? 0);
        if ($n > 0) {
            $notice = __('%d archivo(s) eliminado(s), %s liberados.', $n, mediaCleanupFormatBytes($freed));
        } else {
            $notice = __('No se eliminó ningún archivo.');
        }
    }

    return compact('orphanAudios', 'orphanImages', 'error', 'notice');
}
