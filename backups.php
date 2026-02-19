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

function esc(string $value): string
{
    // Escape HTML centralizado para todo texto dinámico en la vista.
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function addDirectoryToZip(ZipArchive $zip, string $absoluteDir, string $zipRoot): int
{
    // Si el directorio no existe, no se considera error: simplemente no se añade.
    if (!is_dir($absoluteDir)) {
        return 0;
    }

    // Crea explícitamente el directorio raíz dentro del ZIP (images/ o audios/).
    $zip->addEmptyDir($zipRoot);

    $dirLen = strlen($absoluteDir) + 1;
    $filesAdded = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    // Recorre recursivamente el árbol local y replica su estructura dentro del ZIP.
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $relativePath = substr($path, $dirLen);
        $relativePathUnix = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        // No incluye variantes generadas automáticamente dentro de images/generated/.
        if ($zipRoot === 'images' && ($relativePathUnix === 'generated' || str_starts_with($relativePathUnix, 'generated/'))) {
            continue;
        }

        $zipPath = $zipRoot . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        if ($item->isDir()) {
            $zip->addEmptyDir($zipPath);
            continue;
        }

        if ($item->isFile()) {
            $zip->addFile($path, $zipPath);
            $filesAdded++;
        }
    }

    return $filesAdded;
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

if (isset($_GET['action']) && $_GET['action'] === 'export_files') {
    // Exportación de ficheros multimedia como ZIP temporal descargable.
    if (!class_exists('ZipArchive')) {
        $error = 'La extensión ZipArchive no está disponible en este servidor.';
    } else {
        $imagesDir = __DIR__ . '/images';
        $audiosDir = __DIR__ . '/audios';

        if (!is_dir($imagesDir) && !is_dir($audiosDir)) {
            $error = 'No existen los directorios images ni audios para exportar.';
        } else {
            $tmpZipPath = tempnam(sys_get_temp_dir(), 'easy_podcast_media_');
            if ($tmpZipPath === false) {
                $error = 'No se pudo preparar el archivo temporal para exportar.';
            } else {
                $zip = new ZipArchive();
                $openResult = $zip->open($tmpZipPath, ZipArchive::OVERWRITE);
                if ($openResult !== true) {
                    @unlink($tmpZipPath);
                    $error = 'No se pudo crear el archivo ZIP de exportación.';
                } else {
                    $filesCount = 0;
                    $filesCount += addDirectoryToZip($zip, $imagesDir, 'images');
                    $filesCount += addDirectoryToZip($zip, $audiosDir, 'audios');
                    $zip->close();

                    if ($filesCount === 0) {
                        @unlink($tmpZipPath);
                        $error = 'No hay archivos en images/audios para exportar.';
                    } else {
                        $downloadName = 'easy_podcast_files_' . date('Ymd_His') . '.zip';
                        header('Content-Type: application/zip');
                        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
                        header('Content-Length: ' . (string) filesize($tmpZipPath));
                        header('Cache-Control: no-store, no-cache, must-revalidate');
                        header('Pragma: no-cache');
                        readfile($tmpZipPath);
                        // Limpia el ZIP temporal para no dejar residuos en disco.
                        @unlink($tmpZipPath);
                        exit;
                    }
                }
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
    // Importación de ZIP de ficheros (solo se aceptan rutas bajo images/ y audios/).
    if (!class_exists('ZipArchive')) {
        $error = 'La extensión ZipArchive no está disponible en este servidor.';
    } elseif (!isset($_FILES['files_zip']) || !is_array($_FILES['files_zip'])) {
        $error = 'Selecciona un ZIP de ficheros.';
    } else {
        $uploadError = (int) ($_FILES['files_zip']['error'] ?? UPLOAD_ERR_NO_FILE);
        $uploadedPath = (string) ($_FILES['files_zip']['tmp_name'] ?? '');
        $originalName = strtolower((string) ($_FILES['files_zip']['name'] ?? ''));
        $isZip = preg_match('/\.zip$/', $originalName) === 1;

        if ($uploadError !== UPLOAD_ERR_OK || $uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
            $error = 'No se pudo subir el ZIP de ficheros.';
        } elseif (!$isZip) {
            $error = 'El archivo debe tener extensión .zip.';
        } else {
            $zip = new ZipArchive();
            $openResult = $zip->open($uploadedPath);
            if ($openResult !== true) {
                $error = 'No se pudo abrir el ZIP para importar ficheros.';
            } else {
                $writtenFiles = 0;
                $createdDirs = 0;
                $foundValidEntries = 0;
                $importError = '';

                // Recorre entradas una a una para aplicar validación de ruta antes de escribir.
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryStat = $zip->statIndex($i);
                    if (!is_array($entryStat) || !isset($entryStat['name'])) {
                        continue;
                    }
                    $entryName = normalizeZipPath((string) $entryStat['name']);
                    if ($entryName === '') {
                        continue;
                    }

                    // Bloqueo explícito de path traversal y nombres corruptos.
                    if (strpos($entryName, '../') !== false || str_contains($entryName, "\0")) {
                        $importError = 'El ZIP contiene rutas no permitidas.';
                        break;
                    }

                    $isAllowedRoot = str_starts_with($entryName, 'images/') || str_starts_with($entryName, 'audios/');
                    $isAllowedDir = ($entryName === 'images' || $entryName === 'audios');
                    // Ignora cualquier ruta fuera de images/ o audios/.
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
                                $importError = 'No se pudo crear un directorio durante la importación.';
                                break;
                            }
                            $createdDirs++;
                        }
                        continue;
                    }

                    $parentDir = dirname($targetPath);
                    if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                        $importError = 'No se pudo preparar directorios para extraer ficheros.';
                        break;
                    }

                    // Extracción en streaming para no cargar ficheros completos en memoria.
                    $stream = $zip->getStream((string) $entryStat['name']);
                    if ($stream === false) {
                        $importError = 'No se pudo leer un fichero dentro del ZIP.';
                        break;
                    }
                    $out = fopen($targetPath, 'wb');
                    if ($out === false) {
                        fclose($stream);
                        $importError = 'No se pudo escribir un fichero en destino.';
                        break;
                    }

                    while (!feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false) {
                            $importError = 'Error al leer datos del ZIP.';
                            break;
                        }
                        if ($chunk !== '' && fwrite($out, $chunk) === false) {
                            $importError = 'Error al guardar un fichero importado.';
                            break;
                        }
                    }

                    fclose($out);
                    fclose($stream);

                    if ($importError !== '') {
                        break;
                    }
                    $writtenFiles++;
                }

                $zip->close();

                if ($importError !== '') {
                    $error = $importError;
                } elseif ($foundValidEntries === 0) {
                    $error = 'El ZIP no contiene rutas válidas de images/ o audios/.';
                } else {
                    $notice = 'Ficheros importados correctamente. Archivos escritos: '
                        . $writtenFiles . '. Directorios creados: ' . $createdDirs . '.';
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
        <p>Exporta o importa el ZIP con carpetas <code>images/</code> y <code>audios/</code>.</p>
        <div class="db-tools">
          <a class="btn files-export" href="backups.php?action=export_files">Exportar ficheros (images y audios)</a>
          <form class="db-import-form" method="post" action="backups.php" enctype="multipart/form-data">
            <input type="hidden" name="files_action" value="import_files_zip">
            <label for="files_zip">Importar ficheros (ZIP)</label>
            <input id="files_zip" type="file" name="files_zip" accept=".zip" required>
            <button class="btn files-import" type="submit">Importar ficheros</button>
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
