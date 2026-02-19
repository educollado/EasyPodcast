<?php

declare(strict_types=1);

// Herramientas de copias de seguridad:
// - exportar la base de datos actual
// - importar una base de datos con backup previo

// Redirección canónica por host configurado en el podcast y utilidades del feed.
require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/feed_builder.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/sitemap_builder.php';

// El acceso a esta pantalla exige sesión de administrador activa.
session_start();

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
// Fuerza el dominio canónico para evitar acciones de administración desde host alternativo.
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');
$error = '';
$notice = '';
const MEDIA_PART_MAX_BYTES = 133169152; // 127 MiB

function esc(string $value): string
{
    // Escape HTML centralizado para todo texto dinámico en la vista.
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @return array<int, array{abs:string, zip:string, size:int}>
 */
function collectMediaFiles(string $absoluteDir, string $zipRoot): array
{
    if (!is_dir($absoluteDir)) {
        return [];
    }

    $dirLen = strlen($absoluteDir) + 1;
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        $relativePath = substr($path, $dirLen);
        $relativePathUnix = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        if ($zipRoot === 'images' && ($relativePathUnix === 'generated' || str_starts_with($relativePathUnix, 'generated/'))) {
            continue;
        }

        $size = $item->getSize();
        if ($size === false || $size < 0) {
            continue;
        }

        $files[] = [
            'abs' => $path,
            'zip' => $zipRoot . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
            'size' => (int) $size,
        ];
    }

    usort($files, static fn(array $a, array $b): int => strcmp($a['zip'], $b['zip']));
    return $files;
}

/**
 * @param array<int, array{abs:string, zip:string, size:int}> $files
 * @return array{parts:array<int, array{files:array<int, array{abs:string, zip:string, size:int}>, bytes:int}>, skipped:array<int, array{abs:string, zip:string, size:int}>}
 */
function splitMediaFilesIntoParts(array $files, int $maxBytes): array
{
    $parts = [];
    $skipped = [];
    $currentFiles = [];
    $currentBytes = 0;

    foreach ($files as $file) {
        if ($file['size'] > $maxBytes) {
            $skipped[] = $file;
            continue;
        }

        if ($currentBytes > 0 && ($currentBytes + $file['size']) > $maxBytes) {
            $parts[] = ['files' => $currentFiles, 'bytes' => $currentBytes];
            $currentFiles = [];
            $currentBytes = 0;
        }

        $currentFiles[] = $file;
        $currentBytes += $file['size'];
    }

    if ($currentFiles !== []) {
        $parts[] = ['files' => $currentFiles, 'bytes' => $currentBytes];
    }

    return ['parts' => $parts, 'skipped' => $skipped];
}

/**
 * @return array{parts:array<int, array{files:array<int, array{abs:string, zip:string, size:int}>, bytes:int}>, skipped:array<int, array{abs:string, zip:string, size:int}>, totalFiles:int, totalBytes:int, exportedFiles:int}
 */
function buildMediaExportPlan(string $absoluteDir, string $zipRoot): array
{
    $files = collectMediaFiles($absoluteDir, $zipRoot);
    $split = splitMediaFilesIntoParts($files, MEDIA_PART_MAX_BYTES);
    $parts = $split['parts'];
    $skipped = $split['skipped'];
    $totalBytes = 0;
    $exportedFiles = 0;
    foreach ($parts as $part) {
        $totalBytes += $part['bytes'];
        $exportedFiles += count($part['files']);
    }

    return [
        'parts' => $parts,
        'skipped' => $skipped,
        'totalFiles' => count($files),
        'totalBytes' => $totalBytes,
        'exportedFiles' => $exportedFiles,
    ];
}

function mediaPathToHref(string $zipPath): string
{
    $segments = explode('/', ltrim($zipPath, '/'));
    $encoded = array_map(static fn(string $segment): string => rawurlencode($segment), $segments);
    return '/' . implode('/', $encoded);
}

/**
 * @param array<int, array{abs:string, zip:string, size:int}> $partFiles
 */
function streamZipPart(array $partFiles, string $downloadName, string $zipRoot): void
{
    $tmpZipPath = tempnam(sys_get_temp_dir(), 'easy_podcast_part_');
    if ($tmpZipPath === false) {
        throw new RuntimeException('No se pudo preparar el archivo temporal para exportar.');
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($tmpZipPath, ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        @unlink($tmpZipPath);
        throw new RuntimeException('No se pudo crear el archivo ZIP de exportación.');
    }

    $zip->addEmptyDir($zipRoot);
    $filesAdded = 0;
    foreach ($partFiles as $file) {
        if (is_file($file['abs'])) {
            $zip->addFile($file['abs'], $file['zip']);
            $filesAdded++;
        }
    }
    $zip->close();

    if ($filesAdded === 0) {
        @unlink($tmpZipPath);
        throw new RuntimeException('No hay archivos para exportar en esta parte.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($tmpZipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($tmpZipPath);
    @unlink($tmpZipPath);
}

function normalizeZipPath(string $path): string
{
    // Normaliza separadores para tratar todas las rutas ZIP como POSIX.
    $normalized = str_replace('\\', '/', $path);
    while (strpos($normalized, '//') !== false) {
        $normalized = str_replace('//', '/', $normalized);
    }
    return ltrim($normalized, '/');
}

/**
 * @param array<string, mixed> $filesField
 * @return array<int, array{name:string, tmp_name:string, error:int}>
 */
function normalizeUploadedFilesList(array $filesField): array
{
    $names = $filesField['name'] ?? [];
    $tmpNames = $filesField['tmp_name'] ?? [];
    $errors = $filesField['error'] ?? [];

    if (!is_array($names)) {
        return [[
            'name' => (string) ($filesField['name'] ?? ''),
            'tmp_name' => (string) ($filesField['tmp_name'] ?? ''),
            'error' => (int) ($filesField['error'] ?? UPLOAD_ERR_NO_FILE),
        ]];
    }

    $normalized = [];
    foreach ($names as $idx => $name) {
        $normalized[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($tmpNames[$idx] ?? ''),
            'error' => (int) ($errors[$idx] ?? UPLOAD_ERR_NO_FILE),
        ];
    }

    return $normalized;
}

function sanitizeAudioFilename(string $originalName): string
{
    $name = basename($originalName);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
    $name = trim((string) $name, '-._');
    if ($name === '') {
        $name = 'audio-' . date('Ymd-His');
    }

    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    $base = (string) pathinfo($name, PATHINFO_FILENAME);
    if ($ext !== 'mp3') {
        $ext = 'mp3';
    }
    if ($base === '') {
        $base = 'audio-' . date('Ymd-His');
    }

    return $base . '.' . $ext;
}

function resolveUniquePath(string $dir, string $filename): string
{
    $ext = (string) pathinfo($filename, PATHINFO_EXTENSION);
    $base = (string) pathinfo($filename, PATHINFO_FILENAME);
    $candidate = $dir . '/' . $filename;
    $counter = 1;
    while (is_file($candidate)) {
        $suffix = '-' . $counter;
        $candidate = $dir . '/' . $base . $suffix . ($ext !== '' ? '.' . $ext : '');
        $counter++;
    }
    return $candidate;
}

/**
 * @return array{written:int, dirs:int, valid:int}
 */
function importZipIntoMedia(string $uploadedPath): array
{
    $zip = new ZipArchive();
    $openResult = $zip->open($uploadedPath);
    if ($openResult !== true) {
        throw new RuntimeException('No se pudo abrir el ZIP para importar ficheros.');
    }

    $writtenFiles = 0;
    $createdDirs = 0;
    $foundValidEntries = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryStat = $zip->statIndex($i);
        if (!is_array($entryStat) || !isset($entryStat['name'])) {
            continue;
        }
        $entryName = normalizeZipPath((string) $entryStat['name']);
        if ($entryName === '') {
            continue;
        }

        if (strpos($entryName, '../') !== false || str_contains($entryName, "\0")) {
            $zip->close();
            throw new RuntimeException('El ZIP contiene rutas no permitidas.');
        }

        $isAllowedRoot = str_starts_with($entryName, 'images/') || str_starts_with($entryName, 'audios/');
        $isAllowedDir = ($entryName === 'images' || $entryName === 'audios');
        if (!$isAllowedRoot && !$isAllowedDir) {
            continue;
        }
        $foundValidEntries++;

        $targetPath = __DIR__ . '/' . $entryName;
        $isDirEntry = str_ends_with($entryName, '/')
            || (isset($entryStat['size']) && (int) $entryStat['size'] === 0 && str_ends_with($entryName, '/'));

        if ($isDirEntry || $isAllowedDir) {
            $dirPath = rtrim($targetPath, '/');
            if (!is_dir($dirPath)) {
                if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                    $zip->close();
                    throw new RuntimeException('No se pudo crear un directorio durante la importación.');
                }
                $createdDirs++;
            }
            continue;
        }

        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            $zip->close();
            throw new RuntimeException('No se pudo preparar directorios para extraer ficheros.');
        }

        $stream = $zip->getStream((string) $entryStat['name']);
        if ($stream === false) {
            $zip->close();
            throw new RuntimeException('No se pudo leer un fichero dentro del ZIP.');
        }
        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            fclose($stream);
            $zip->close();
            throw new RuntimeException('No se pudo escribir un fichero en destino.');
        }

        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($out);
                fclose($stream);
                $zip->close();
                throw new RuntimeException('Error al leer datos del ZIP.');
            }
            if ($chunk !== '' && fwrite($out, $chunk) === false) {
                fclose($out);
                fclose($stream);
                $zip->close();
                throw new RuntimeException('Error al guardar un fichero importado.');
            }
        }

        fclose($out);
        fclose($stream);
        $writtenFiles++;
    }

    $zip->close();

    if ($foundValidEntries === 0) {
        throw new RuntimeException('El ZIP no contiene rutas válidas de images/ o audios/.');
    }

    return ['written' => $writtenFiles, 'dirs' => $createdDirs, 'valid' => $foundValidEntries];
}

if (isset($_GET['action']) && $_GET['action'] === 'export_db') {
    // Exportación directa de la base de datos actual.
    // No crea copia persistente en servidor: transmite el fichero existente y termina.
    if (!is_file($dbPath)) {
        $error = 'No se encontró la base de datos para exportar.';
    } else {
        $downloadName = 'easy_podcast_backup_' . date('Ymd_His') . '.sqlite';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($dbPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        readfile($dbPath);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'export_media_part') {
    // Exportación de ficheros multimedia en partes <= 127 MiB.
    if (!class_exists('ZipArchive')) {
        $error = 'La extensión ZipArchive no está disponible en este servidor.';
    } else {
        $type = (string) ($_GET['type'] ?? '');
        $part = max(1, (int) ($_GET['part'] ?? 1));
        $isImages = $type === 'images';
        $isAudios = $type === 'audios';

        if (!$isImages && !$isAudios) {
            $error = 'Tipo de exportación inválido.';
        } else {
            $zipRoot = $isImages ? 'images' : 'audios';
            $sourceDir = __DIR__ . '/' . $zipRoot;

            try {
                $plan = buildMediaExportPlan($sourceDir, $zipRoot);
                if ($plan['totalFiles'] === 0) {
                    $error = 'No hay archivos para exportar en ' . $zipRoot . '/.';
                } elseif (count($plan['parts']) === 0) {
                    $error = 'No hay ficheros exportables en ZIP para ' . $zipRoot
                        . '/. Descarga manualmente los que superan 127 MB desde la lista inferior.';
                } elseif (!isset($plan['parts'][$part - 1])) {
                    $error = 'La parte solicitada no existe para ' . $zipRoot . '.';
                } else {
                    $downloadName = 'easy_podcast_' . $zipRoot
                        . '_part' . str_pad((string) $part, 3, '0', STR_PAD_LEFT)
                        . '_' . date('Ymd_His') . '.zip';
                    streamZipPart($plan['parts'][$part - 1]['files'], $downloadName, $zipRoot);
                    exit;
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['db_action'] ?? '') === 'import_db') {
    // Importación de base de datos SQLite.
    // Flujo: validar subida -> validar estructura -> backup previo -> restaurar -> regenerar feed.
    if (!isset($_FILES['db_file']) || !is_array($_FILES['db_file'])) {
        $error = 'Selecciona un archivo de base de datos.';
    } else {
        $uploadError = (int) ($_FILES['db_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        $uploadedPath = (string) ($_FILES['db_file']['tmp_name'] ?? '');
        $originalName = strtolower((string) ($_FILES['db_file']['name'] ?? ''));
        $validExtension = preg_match('/\.(sqlite|db)$/', $originalName) === 1;

        if ($uploadError !== UPLOAD_ERR_OK || $uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
            $error = 'No se pudo subir el archivo.';
        } elseif (!$validExtension) {
            $error = 'El archivo debe tener extensión .sqlite o .db.';
        } else {
            try {
                // Verificación rápida de integridad funcional: la DB debe contener tablas clave.
                $probe = new PDO('sqlite:' . $uploadedPath);
                $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $hasPodcastTable = (int) $probe
                    ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'podcast'")
                    ->fetchColumn();
                $hasEpisodesTable = (int) $probe
                    ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'episodes'")
                    ->fetchColumn();

                if ($hasPodcastTable === 0 || $hasEpisodesTable === 0) {
                    $error = 'La base de datos importada no parece válida para EasyPodcast.';
                } elseif (!class_exists('SQLite3')) {
                    $error = 'La extensión SQLite3 no está disponible en este servidor.';
                } else {
                    // Antes de sobrescribir, crea snapshot de seguridad de la base actual.
                    $backupDir = __DIR__ . '/backups';
                    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                        $error = 'No se pudo crear el directorio de backups.';
                    } else {
                        $backupPath = $backupDir . '/podcast-before-import-' . date('Ymd_His') . '.sqlite';
                        if (!copy($dbPath, $backupPath)) {
                            $error = 'No se pudo crear el backup previo de seguridad.';
                        } else {
                            $probe = null;

                            // Restauración binaria usando API nativa de SQLite para minimizar riesgos.
                            $sourceDb = new SQLite3($uploadedPath, SQLITE3_OPEN_READONLY);
                            $targetDb = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
                            $importOk = $sourceDb->backup($targetDb);
                            $sourceDb->close();
                            $targetDb->close();

                            if (!$importOk) {
                                $error = 'Falló la importación de la base de datos.';
                            } else {
                                $pdo = new PDO('sqlite:' . $dbPath);
                                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                                try {
                                    // Tras importar, sincroniza feed.xml/sitemap.xml con la nueva base.
                                    writePodcastFeedFile($pdo, __DIR__ . '/feed.xml', resolveFeedSelfHref($pdo));
                                    writePodcastSitemapFile($pdo, __DIR__ . '/sitemap.xml');
                                    $notice = 'Base de datos importada correctamente y feed.xml/sitemap.xml regenerados.';
                                } catch (Throwable $feedError) {
                                    $notice = 'Base de datos importada correctamente, pero no se pudo regenerar feed.xml/sitemap.xml.';
                                }
                                if (!clearWebCache()) {
                                    $notice .= ' (Aviso: no se pudo limpiar completamente la caché)';
                                }

                                // Limpia el backup temporal tras importación satisfactoria.
                                if (!@unlink($backupPath) && is_file($backupPath)) {
                                    $notice .= ' (Aviso: no se pudo borrar el backup temporal en /backups)';
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $error = 'No se pudo validar/importar el archivo: ' . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['files_action'] ?? '') === 'import_files_zip') {
    // Importación de ZIP(s) de media y/o audios MP3 sueltos.
    $zipUploads = isset($_FILES['files_zip']) && is_array($_FILES['files_zip'])
        ? normalizeUploadedFilesList($_FILES['files_zip'])
        : [];
    $audioUploads = isset($_FILES['audio_files']) && is_array($_FILES['audio_files'])
        ? normalizeUploadedFilesList($_FILES['audio_files'])
        : [];

    $hasZipCandidate = false;
    foreach ($zipUploads as $upload) {
        if (!((int) $upload['error'] === UPLOAD_ERR_NO_FILE && (string) $upload['name'] === '')) {
            $hasZipCandidate = true;
            break;
        }
    }
    $hasAudioCandidate = false;
    foreach ($audioUploads as $upload) {
        if (!((int) $upload['error'] === UPLOAD_ERR_NO_FILE && (string) $upload['name'] === '')) {
            $hasAudioCandidate = true;
            break;
        }
    }

    if (!$hasZipCandidate && !$hasAudioCandidate) {
        $error = 'Selecciona al menos un ZIP o un audio MP3.';
    } else {
        $archivesProcessed = 0;
        $writtenFiles = 0;
        $createdDirs = 0;
        $audiosUploaded = 0;

        if ($hasZipCandidate && !class_exists('ZipArchive')) {
            $error = 'La extensión ZipArchive no está disponible en este servidor.';
        }

        if ($error === '') {
            foreach ($zipUploads as $upload) {
                $uploadError = (int) $upload['error'];
                $uploadedPath = (string) $upload['tmp_name'];
                $originalName = strtolower((string) $upload['name']);

                if ($uploadError === UPLOAD_ERR_NO_FILE && $originalName === '') {
                    continue;
                }
                if ($uploadError !== UPLOAD_ERR_OK || $uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
                    $error = 'No se pudo subir uno de los ZIP de ficheros.';
                    break;
                }
                if (preg_match('/\.zip$/', $originalName) !== 1) {
                    $error = 'Todos los ficheros ZIP deben tener extensión .zip.';
                    break;
                }

                try {
                    $result = importZipIntoMedia($uploadedPath);
                    $writtenFiles += $result['written'];
                    $createdDirs += $result['dirs'];
                    $archivesProcessed++;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    break;
                }
            }
        }

        if ($error === '') {
            $audiosDir = __DIR__ . '/audios';
            if (!is_dir($audiosDir) && !mkdir($audiosDir, 0755, true) && !is_dir($audiosDir)) {
                $error = 'No se pudo crear el directorio audios/.';
            } else {
                foreach ($audioUploads as $upload) {
                    $uploadError = (int) $upload['error'];
                    $uploadedPath = (string) $upload['tmp_name'];
                    $originalName = (string) $upload['name'];
                    $lowerName = strtolower($originalName);

                    if ($uploadError === UPLOAD_ERR_NO_FILE && $originalName === '') {
                        continue;
                    }
                    if ($uploadError !== UPLOAD_ERR_OK || $uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
                        $error = 'No se pudo subir uno de los audios MP3.';
                        break;
                    }
                    if (preg_match('/\.mp3$/', $lowerName) !== 1) {
                        $error = 'Todos los audios sueltos deben tener extensión .mp3.';
                        break;
                    }

                    $safeName = sanitizeAudioFilename($originalName);
                    $targetPath = resolveUniquePath($audiosDir, $safeName);
                    if (!move_uploaded_file($uploadedPath, $targetPath)) {
                        $error = 'No se pudo guardar uno de los audios MP3 en audios/.';
                        break;
                    }
                    $audiosUploaded++;
                }
            }
        }

        if ($error === '' && $archivesProcessed === 0 && $audiosUploaded === 0) {
            $error = 'Selecciona al menos un ZIP o un audio MP3.';
        } elseif ($error === '') {
            $notice = 'Importacion completada. ZIP procesados: ' . $archivesProcessed
                . '. Archivos escritos desde ZIP: ' . $writtenFiles
                . '. Directorios creados: ' . $createdDirs
                . '. Audios MP3 subidos: ' . $audiosUploaded . '.';
            try {
                $pdo = new PDO('sqlite:' . $dbPath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                writePodcastSitemapFile($pdo, __DIR__ . '/sitemap.xml');
            } catch (Throwable $sitemapError) {
                $notice .= ' (Aviso: no se pudo regenerar sitemap.xml)';
            }
            if (!clearWebCache()) {
                $notice .= ' (Aviso: no se pudo limpiar completamente la caché)';
            }
        }
    }
}

$imagesExport = ['parts' => [], 'skipped' => [], 'totalFiles' => 0, 'totalBytes' => 0, 'exportedFiles' => 0, 'error' => ''];
$audiosExport = ['parts' => [], 'skipped' => [], 'totalFiles' => 0, 'totalBytes' => 0, 'exportedFiles' => 0, 'error' => ''];
if (class_exists('ZipArchive')) {
    try {
        $imagesPlan = buildMediaExportPlan(__DIR__ . '/images', 'images');
        $imagesExport['parts'] = $imagesPlan['parts'];
        $imagesExport['skipped'] = $imagesPlan['skipped'];
        $imagesExport['totalFiles'] = $imagesPlan['totalFiles'];
        $imagesExport['totalBytes'] = $imagesPlan['totalBytes'];
        $imagesExport['exportedFiles'] = $imagesPlan['exportedFiles'];
    } catch (Throwable $e) {
        $imagesExport['error'] = $e->getMessage();
    }

    try {
        $audiosPlan = buildMediaExportPlan(__DIR__ . '/audios', 'audios');
        $audiosExport['parts'] = $audiosPlan['parts'];
        $audiosExport['skipped'] = $audiosPlan['skipped'];
        $audiosExport['totalFiles'] = $audiosPlan['totalFiles'];
        $audiosExport['totalBytes'] = $audiosPlan['totalBytes'];
        $audiosExport['exportedFiles'] = $audiosPlan['exportedFiles'];
    } catch (Throwable $e) {
        $audiosExport['error'] = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Copias de seguridad</title>
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
  <main class="card backups-card">
    <h1>Copias de seguridad</h1>
    <p>Gestiona por separado la base de datos y los ficheros multimedia.</p>

    <?php if ($error !== ''): ?>
      <div class="error"><?= esc($error) ?></div>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice"><?= esc($notice) ?></div>
    <?php endif; ?>

    <div class="backup-groups">
      <section class="tool-box" aria-label="Bloque base de datos">
        <h2>Base de Datos</h2>
        <p>Exporta o importa el archivo SQLite del podcast.</p>
        <div class="db-tools">
          <a class="btn db-export" href="backups.php?action=export_db">Exportar base de datos</a>
          <form class="db-import-form" method="post" action="backups.php" enctype="multipart/form-data">
            <input type="hidden" name="db_action" value="import_db">
            <label for="db_file">Importar base de datos</label>
            <input id="db_file" type="file" name="db_file" accept=".sqlite,.db" required>
            <button class="btn db-import" type="submit">Importar base de datos</button>
          </form>
        </div>
      </section>

      <section class="tool-box" aria-label="Bloque ficheros">
        <h2>Ficheros</h2>
        <p>Exporta por separado <code>images/</code> y <code>audios/</code> en partes ZIP de hasta 127 MB.</p>
        <div class="db-tools">
          <div>
            <strong>Exportar imágenes</strong>
            <?php if ($imagesExport['error'] !== ''): ?>
              <p class="error"><?= esc((string) $imagesExport['error']) ?></p>
            <?php elseif ($imagesExport['totalFiles'] === 0): ?>
              <p>No hay ficheros en <code>images/</code>.</p>
            <?php else: ?>
              <p>
                Total: <?= (int) $imagesExport['totalFiles'] ?> ficheros.
                Exportables en ZIP: <?= (int) $imagesExport['exportedFiles'] ?> en <?= count($imagesExport['parts']) ?> parte(s).
              </p>
              <?php foreach ($imagesExport['parts'] as $idx => $part): ?>
                <a class="btn files-export" href="backups.php?action=export_media_part&amp;type=images&amp;part=<?= $idx + 1 ?>">
                  Descargar imágenes parte <?= $idx + 1 ?> (<?= number_format($part['bytes'] / 1048576, 2) ?> MB)
                </a>
              <?php endforeach; ?>
              <?php if (count($imagesExport['skipped']) > 0): ?>
                <p class="error">
                  Algunos ficheros de <code>images/</code> superan 127 MB y no se incluyen en ZIP.
                  Descargalos manualmente:
                </p>
                <?php foreach ($imagesExport['skipped'] as $skipped): ?>
                  <p>
                    <a href="<?= esc(mediaPathToHref((string) $skipped['zip'])) ?>" target="_blank" rel="noopener">
                      <?= esc((string) $skipped['zip']) ?>
                    </a>
                    (<?= number_format(((int) $skipped['size']) / 1048576, 2) ?> MB)
                  </p>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <div>
            <strong>Exportar audios</strong>
            <?php if ($audiosExport['error'] !== ''): ?>
              <p class="error"><?= esc((string) $audiosExport['error']) ?></p>
            <?php elseif ($audiosExport['totalFiles'] === 0): ?>
              <p>No hay ficheros en <code>audios/</code>.</p>
            <?php else: ?>
              <p>
                Total: <?= (int) $audiosExport['totalFiles'] ?> ficheros.
                Exportables en ZIP: <?= (int) $audiosExport['exportedFiles'] ?> en <?= count($audiosExport['parts']) ?> parte(s)
                y <?= count($audiosExport['skipped']) ?> audios no exportables en ZIP.
              </p>
              <?php foreach ($audiosExport['parts'] as $idx => $part): ?>
                <a class="btn files-export" href="backups.php?action=export_media_part&amp;type=audios&amp;part=<?= $idx + 1 ?>">
                  Descargar audios parte <?= $idx + 1 ?> (<?= number_format($part['bytes'] / 1048576, 2) ?> MB)
                </a>
              <?php endforeach; ?>
              <?php if (count($audiosExport['skipped']) > 0): ?>
                <p class="error">
                  Algunos ficheros de <code>audios/</code> superan 127 MB y no se incluyen en ZIP.
                  Descargalos manualmente:
                </p>
                <?php foreach ($audiosExport['skipped'] as $skipped): ?>
                  <p>
                    <a href="<?= esc(mediaPathToHref((string) $skipped['zip'])) ?>" target="_blank" rel="noopener">
                      <?= esc((string) $skipped['zip']) ?>
                    </a>
                    (<?= number_format(((int) $skipped['size']) / 1048576, 2) ?> MB)
                  </p>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <form class="db-import-form" method="post" action="backups.php" enctype="multipart/form-data">
            <input type="hidden" name="files_action" value="import_files_zip">
            <label for="files_zip">Importar ficheros (uno o varios ZIP o audios)</label>
            <input id="files_zip" type="file" name="files_zip[]" accept=".zip" multiple>
            <label for="audio_files">Audios MP3 sueltos (opcional)</label>
            <input id="audio_files" type="file" name="audio_files[]" accept=".mp3,audio/mpeg" multiple>
            <button class="btn files-import" type="submit">Importar ZIP(s) y/o audios</button>
          </form>
        </div>
      </section>
    </div>

    <div class="actions">
      <a class="btn manage" href="admin.php">Volver al panel</a>
      <a class="btn logout" href="admin.php?logout=1">Cerrar sesión</a>
    </div>
  </main>
</body>
</html>
