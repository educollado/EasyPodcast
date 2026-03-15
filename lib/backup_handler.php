<?php

declare(strict_types=1);

// Lógica de negocio para backups.php:
// - exportar/importar base de datos
// - exportar/importar ficheros multimedia en partes ZIP

require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/sitemap_builder.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/i18n.php';

const MEDIA_PART_MAX_BYTES = 133169152; // 127 MiB

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

/**
 * Convierte la ruta ZIP de un fichero multimedia a una URL relativa correctamente codificada.
 * Cada segmento de ruta se codifica con rawurlencode para soportar caracteres especiales.
 */
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
        throw new RuntimeException(__('No se pudo preparar el archivo temporal para exportar.'));
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($tmpZipPath, ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        @unlink($tmpZipPath);
        throw new RuntimeException(__('No se pudo crear el archivo ZIP de exportación.'));
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
        throw new RuntimeException(__('No hay archivos para exportar en esta parte.'));
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) filesize($tmpZipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($tmpZipPath);
    @unlink($tmpZipPath);
}

/**
 * Normaliza los separadores de ruta de una entrada ZIP a formato POSIX y elimina la barra inicial.
 */
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

/**
 * Sanea el nombre de un fichero de audio MP3 para guardarlo de forma segura en /audios/.
 * Elimina caracteres no ASCII y asegura que la extensión sea .mp3.
 */
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

/**
 * Devuelve una ruta única en $dir para $filename añadiendo un contador numérico si ya existe.
 * Evita sobrescribir ficheros existentes al importar audios MP3.
 */
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
function importZipIntoMedia(string $uploadedPath, string $projectRoot): array
{
    $zip = new ZipArchive();
    $openResult = $zip->open($uploadedPath);
    if ($openResult !== true) {
        throw new RuntimeException(__('No se pudo abrir el ZIP para importar ficheros.'));
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
            throw new RuntimeException(__('El ZIP contiene rutas no permitidas.'));
        }

        $isAllowedRoot = str_starts_with($entryName, 'images/') || str_starts_with($entryName, 'audios/');
        $isAllowedDir = ($entryName === 'images' || $entryName === 'audios');
        if (!$isAllowedRoot && !$isAllowedDir) {
            continue;
        }
        $foundValidEntries++;

        $targetPath = $projectRoot . '/' . $entryName;
        $isDirEntry = str_ends_with($entryName, '/')
            || (isset($entryStat['size']) && (int) $entryStat['size'] === 0 && str_ends_with($entryName, '/'));

        if ($isDirEntry || $isAllowedDir) {
            $dirPath = rtrim($targetPath, '/');
            if (!is_dir($dirPath)) {
                if (!mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
                    $zip->close();
                    throw new RuntimeException(__('No se pudo crear un directorio durante la importación.'));
                }
                $createdDirs++;
            }
            continue;
        }

        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            $zip->close();
            throw new RuntimeException(__('No se pudo preparar directorios para extraer ficheros.'));
        }

        $stream = $zip->getStream((string) $entryStat['name']);
        if ($stream === false) {
            $zip->close();
            throw new RuntimeException(__('No se pudo leer un fichero dentro del ZIP.'));
        }
        $out = fopen($targetPath, 'wb');
        if ($out === false) {
            fclose($stream);
            $zip->close();
            throw new RuntimeException(__('No se pudo escribir un fichero en destino.'));
        }

        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($out);
                fclose($stream);
                $zip->close();
                throw new RuntimeException(__('Error al leer datos del ZIP.'));
            }
            if ($chunk !== '' && fwrite($out, $chunk) === false) {
                fclose($out);
                fclose($stream);
                $zip->close();
                throw new RuntimeException(__('Error al guardar un fichero importado.'));
            }
        }

        fclose($out);
        fclose($stream);
        $writtenFiles++;
    }

    $zip->close();

    if ($foundValidEntries === 0) {
        throw new RuntimeException(__('El ZIP no contiene rutas válidas de images/ o audios/.'));
    }

    return ['written' => $writtenFiles, 'dirs' => $createdDirs, 'valid' => $foundValidEntries];
}

/**
 * Procesa las acciones GET/POST de backups y precalcula los planes de exportación.
 * Puede redirigir/salir en acciones de exportación binaria.
 *
 * @return array{error:string, notice:string, imagesExport:array<string,mixed>, audiosExport:array<string,mixed>}
 */
function loadBackupsData(string $dbPath, string $projectRoot): array
{
    $error = '';
    $notice = '';

    if (isset($_GET['action']) && $_GET['action'] === 'export_db') {
        // Exportación directa de la base de datos actual.
        // No crea copia persistente en servidor: transmite el fichero existente y termina.
        if (!is_file($dbPath)) {
            $error = __('No se encontró la base de datos para exportar.');
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
            $error = __('La extensión ZipArchive no está disponible en este servidor.');
        } else {
            $type = (string) ($_GET['type'] ?? '');
            $part = max(1, (int) ($_GET['part'] ?? 1));
            $isImages = $type === 'images';
            $isAudios = $type === 'audios';

            if (!$isImages && !$isAudios) {
                $error = __('Tipo de exportación inválido.');
            } else {
                $zipRoot = $isImages ? 'images' : 'audios';
                $sourceDir = $projectRoot . '/' . $zipRoot;

                try {
                    $plan = buildMediaExportPlan($sourceDir, $zipRoot);
                    if ($plan['totalFiles'] === 0) {
                        $error = __('No hay archivos para exportar en %s/.', $zipRoot);
                    } elseif (count($plan['parts']) === 0) {
                        $error = __('No hay ficheros exportables en ZIP para %s/. Descarga manualmente los que superan 127 MB desde la lista inferior.', $zipRoot);
                    } elseif (!isset($plan['parts'][$part - 1])) {
                        $error = __('La parte solicitada no existe para %s.', $zipRoot);
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
        csrf_verify();
        // Importación de base de datos SQLite.
        // Flujo: validar subida -> validar estructura -> backup previo -> restaurar -> regenerar feed.
        if (!isset($_FILES['db_file']) || !is_array($_FILES['db_file'])) {
            $error = __('Selecciona un archivo de base de datos.');
        } else {
            $uploadError = (int) ($_FILES['db_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            $uploadedPath = (string) ($_FILES['db_file']['tmp_name'] ?? '');
            $originalName = strtolower((string) ($_FILES['db_file']['name'] ?? ''));
            $validExtension = preg_match('/\.(sqlite|db)$/', $originalName) === 1;

            if ($uploadError !== UPLOAD_ERR_OK || $uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
                $error = __('No se pudo subir el archivo.');
            } elseif (!$validExtension) {
                $error = __('El archivo debe tener extensión .sqlite o .db.');
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
                        $error = __('La base de datos importada no parece válida para EasyPodcast.');
                    } elseif (!class_exists('SQLite3')) {
                        $error = __('La extensión SQLite3 no está disponible en este servidor.');
                    } else {
                        // Antes de sobrescribir, crea snapshot de seguridad de la base actual.
                        $backupDir = $projectRoot . '/backups';
                        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                            $error = __('No se pudo crear el directorio de backups.');
                        } else {
                            $backupPath = $backupDir . '/podcast-before-import-' . date('Ymd_His') . '.sqlite';
                            if (!copy($dbPath, $backupPath)) {
                                $error = __('No se pudo crear el backup previo de seguridad.');
                            } else {
                                $probe = null;

                                // Restauración binaria usando API nativa de SQLite para minimizar riesgos.
                                $sourceDb = new SQLite3($uploadedPath, SQLITE3_OPEN_READONLY);
                                $targetDb = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
                                $importOk = $sourceDb->backup($targetDb);
                                $sourceDb->close();
                                $targetDb->close();

                                if (!$importOk) {
                                    $error = __('Falló la importación de la base de datos.');
                                } else {
                                    $pdo = new PDO('sqlite:' . $dbPath);
                                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                                    try {
                                        // Tras importar, sincroniza feed.xml/sitemap.xml con la nueva base.
                                        writePodcastFeedFile($pdo, $projectRoot . '/feed.xml', resolveFeedSelfHref($pdo));
                                        writePodcastSitemapFile($pdo, $projectRoot . '/sitemap.xml');
                                        $notice = __('Base de datos importada correctamente y feed.xml/sitemap.xml regenerados.');
                                    } catch (Throwable $feedError) {
                                        $notice = __('Base de datos importada correctamente, pero no se pudo regenerar feed.xml/sitemap.xml.');
                                    }
                                    if (!clearWebCache()) {
                                        $notice .= ' ' . __('(Aviso: no se pudo limpiar completamente la caché)');
                                    }

                                    // Limpia el backup temporal tras importación satisfactoria.
                                    if (!@unlink($backupPath) && is_file($backupPath)) {
                                        $notice .= ' ' . __('(Aviso: no se pudo borrar el backup temporal en /backups)');
                                    }
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $error = __('No se pudo validar/importar el archivo: %s', $e->getMessage());
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['files_action'] ?? '') === 'import_files_zip') {
        csrf_verify();
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
            $error = __('Selecciona al menos un ZIP o un audio MP3.');
        } else {
            $archivesProcessed = 0;
            $writtenFiles = 0;
            $createdDirs = 0;
            $audiosUploaded = 0;

            if ($hasZipCandidate && !class_exists('ZipArchive')) {
                $error = __('La extensión ZipArchive no está disponible en este servidor.');
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
                        $error = __('No se pudo subir uno de los ZIP de ficheros.');
                        break;
                    }
                    if (preg_match('/\.zip$/', $originalName) !== 1) {
                        $error = __('Todos los ficheros ZIP deben tener extensión .zip.');
                        break;
                    }

                    try {
                        $result = importZipIntoMedia($uploadedPath, $projectRoot);
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
                $audiosDir = $projectRoot . '/audios';
                if (!is_dir($audiosDir) && !mkdir($audiosDir, 0755, true) && !is_dir($audiosDir)) {
                    $error = __('No se pudo crear el directorio audios/.');
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
                            $error = __('No se pudo subir uno de los audios MP3.');
                            break;
                        }
                        if (preg_match('/\.mp3$/', $lowerName) !== 1) {
                            $error = __('Todos los audios sueltos deben tener extensión .mp3.');
                            break;
                        }

                        $safeName = sanitizeAudioFilename($originalName);
                        $targetPath = resolveUniquePath($audiosDir, $safeName);
                        if (!move_uploaded_file($uploadedPath, $targetPath)) {
                            $error = __('No se pudo guardar uno de los audios MP3 en audios/.');
                            break;
                        }
                        $audiosUploaded++;
                    }
                }
            }

            if ($error === '' && $archivesProcessed === 0 && $audiosUploaded === 0) {
                $error = __('Selecciona al menos un ZIP o un audio MP3.');
            } elseif ($error === '') {
                $notice = __('Importacion completada. ZIP procesados: %d. Archivos escritos desde ZIP: %d. Directorios creados: %d. Audios MP3 subidos: %d.', $archivesProcessed, $writtenFiles, $createdDirs, $audiosUploaded);
                try {
                    $pdo = new PDO('sqlite:' . $dbPath);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    writePodcastSitemapFile($pdo, $projectRoot . '/sitemap.xml');
                } catch (Throwable $sitemapError) {
                    $notice .= ' ' . __('(Aviso: no se pudo regenerar sitemap.xml)');
                }
                if (!clearWebCache()) {
                    $notice .= ' ' . __('(Aviso: no se pudo limpiar completamente la caché)');
                }
            }
        }
    }

    $imagesExport = ['parts' => [], 'skipped' => [], 'totalFiles' => 0, 'totalBytes' => 0, 'exportedFiles' => 0, 'error' => ''];
    $audiosExport = ['parts' => [], 'skipped' => [], 'totalFiles' => 0, 'totalBytes' => 0, 'exportedFiles' => 0, 'error' => ''];
    if (class_exists('ZipArchive')) {
        try {
            $imagesPlan = buildMediaExportPlan($projectRoot . '/images', 'images');
            $imagesExport['parts'] = $imagesPlan['parts'];
            $imagesExport['skipped'] = $imagesPlan['skipped'];
            $imagesExport['totalFiles'] = $imagesPlan['totalFiles'];
            $imagesExport['totalBytes'] = $imagesPlan['totalBytes'];
            $imagesExport['exportedFiles'] = $imagesPlan['exportedFiles'];
        } catch (Throwable $e) {
            $imagesExport['error'] = $e->getMessage();
        }

        try {
            $audiosPlan = buildMediaExportPlan($projectRoot . '/audios', 'audios');
            $audiosExport['parts'] = $audiosPlan['parts'];
            $audiosExport['skipped'] = $audiosPlan['skipped'];
            $audiosExport['totalFiles'] = $audiosPlan['totalFiles'];
            $audiosExport['totalBytes'] = $audiosPlan['totalBytes'];
            $audiosExport['exportedFiles'] = $audiosPlan['exportedFiles'];
        } catch (Throwable $e) {
            $audiosExport['error'] = $e->getMessage();
        }
    }

    return compact('error', 'notice', 'imagesExport', 'audiosExport');
}
