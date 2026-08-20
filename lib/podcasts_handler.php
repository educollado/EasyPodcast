<?php

declare(strict_types=1);

require_once __DIR__ . '/podcast_context.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/cache_service.php';

/** @return array{podcasts:array,settings:array,error:string,notice:string,backup_file:string} */
function loadPodcastsManagementData(string $dbPath, string $projectRoot): array
{
    $error = '';
    $notice = '';
    $backupFile = '';
    $pdo = openPodcastDatabase($dbPath);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $title = trim((string) ($_POST['title'] ?? ''));
                $slug = normalizePodcastSlug((string) ($_POST['slug'] ?? ''));
                $slugError = validatePodcastSlug($slug);
                if ($title === '') {
                    throw new RuntimeException(__('El título del podcast es obligatorio.'));
                }
                if ($slugError !== null) {
                    throw new RuntimeException($slugError);
                }
                $exists = $pdo->prepare('SELECT 1 FROM podcast WHERE slug = :slug LIMIT 1');
                $exists->execute([':slug' => $slug]);
                if ($exists->fetchColumn()) {
                    throw new RuntimeException(__('Ese directorio ya está siendo utilizado por otro podcast.'));
                }
                assertPodcastPathsAvailable($projectRoot, $slug);
                $baseUrl = requestBaseUrl();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO podcast
                         (title, description, link, language, app_language, admin_theme, slug, created_at, updated_at)
                         VALUES (:title, '', :link, 'es-ES', :app_language, 'easypodcast', :slug, datetime('now'), datetime('now'))"
                    );
                    $stmt->execute([
                        ':title' => $title,
                        ':link' => $baseUrl . '/' . $slug,
                        ':app_language' => i18n_current_locale(),
                        ':slug' => $slug,
                    ]);
                    $podcastId = (int) $pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO social (podcast_id) VALUES (:podcast_id)')->execute([':podcast_id' => $podcastId]);
                    ensurePodcastMediaDirectories($projectRoot, $slug);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    removePodcastDirectory($projectRoot . '/audios/' . $slug);
                    removePodcastDirectory($projectRoot . '/images/' . $slug);
                    throw $e;
                }
                clearWebCache();
                $notice = __('Podcast creado correctamente.');
            } elseif ($action === 'save_settings') {
                saveMultipodcastSettings($pdo);
                clearWebCache();
                $notice = __('Configuración multipodcast guardada correctamente.');
            } elseif ($action === 'rename_slug') {
                renamePodcastSlug($pdo, $projectRoot, (int) ($_POST['podcast_id'] ?? 0), (string) ($_POST['slug'] ?? ''));
                clearWebCache();
                $notice = __('Directorio del podcast actualizado correctamente.');
            } elseif ($action === 'delete') {
                $result = deletePodcastWithBackup($pdo, $dbPath, $projectRoot, (int) ($_POST['podcast_id'] ?? 0), (string) ($_POST['confirm_title'] ?? ''));
                clearWebCache();
                $backupFile = $result['backup_file'];
                $notice = __('Podcast borrado correctamente. Descarga y conserva su copia de seguridad.');
                unset($_SESSION['active_podcast_id']);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $podcasts = $pdo->query(
        "SELECT p.*, COUNT(e.id) AS episode_count
         FROM podcast p LEFT JOIN episodes e ON e.podcast_id = p.id
         GROUP BY p.id ORDER BY p.title COLLATE NOCASE ASC"
    )->fetchAll();
    $settings = loadAppSettings($pdo);
    return compact('podcasts', 'settings', 'error', 'notice') + ['backup_file' => $backupFile];
}

function requestBaseUrl(): string
{
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $scheme . '://' . ($host !== '' ? $host : 'localhost');
}

function ensurePodcastMediaDirectories(string $projectRoot, string $slug): void
{
    foreach (['audios', 'images', 'images/' . $slug . '/generated'] as $relative) {
        $path = rtrim($projectRoot, '/') . '/' . ($relative === 'audios' || $relative === 'images' ? $relative . '/' . $slug : $relative);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(__('No se pudo crear el directorio de medios del podcast.'));
        }
    }
}

function assertPodcastPathsAvailable(string $projectRoot, string $slug): void
{
    foreach ([$projectRoot . '/' . $slug, $projectRoot . '/audios/' . $slug, $projectRoot . '/images/' . $slug] as $path) {
        if (file_exists($path)) {
            throw new RuntimeException(__('Ese directorio está ocupado en el servidor. Elige otro.'));
        }
    }
}

function saveMultipodcastSettings(PDO $pdo): void
{
    $enabled = isset($_POST['multipodcast_enabled']) ? 1 : 0;
    $homepageId = ($_POST['homepage_podcast_id'] ?? '') !== '' ? (int) $_POST['homepage_podcast_id'] : null;
    if ($enabled === 1) {
        $missing = (int) $pdo->query("SELECT COUNT(*) FROM podcast WHERE slug IS NULL OR slug = ''")->fetchColumn();
        if ($missing > 0) {
            throw new RuntimeException(__('Todos los podcasts deben tener un directorio antes de activar Multipodcast.'));
        }
    }
    if ($homepageId !== null && podcastById($pdo, $homepageId) === null) {
        throw new RuntimeException(__('El podcast elegido para la portada no existe.'));
    }
    $stmt = $pdo->prepare('UPDATE app_settings SET multipodcast_enabled = :enabled, homepage_podcast_id = :homepage WHERE id = 1');
    $stmt->bindValue(':enabled', $enabled, PDO::PARAM_INT);
    $stmt->bindValue(':homepage', $homepageId, $homepageId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();
}

function renamePodcastSlug(PDO $pdo, string $projectRoot, int $podcastId, string $requestedSlug): void
{
    $podcast = podcastById($pdo, $podcastId);
    if ($podcast === null) {
        throw new RuntimeException(__('El podcast no existe.'));
    }
    $slug = normalizePodcastSlug($requestedSlug);
    $slugError = validatePodcastSlug($slug);
    if ($slugError !== null) {
        throw new RuntimeException($slugError);
    }
    $exists = $pdo->prepare('SELECT 1 FROM podcast WHERE slug = :slug AND id != :id LIMIT 1');
    $exists->execute([':slug' => $slug, ':id' => $podcastId]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException(__('Ese directorio ya está siendo utilizado por otro podcast.'));
    }
    $oldSlug = trim((string) ($podcast['slug'] ?? ''));
    if ($oldSlug === '' && $oldSlug !== $slug) {
        assertPodcastPathsAvailable($projectRoot, $slug);
        migrateLegacyPodcastMedia($pdo, $projectRoot, $podcastId, $slug);
    }
    if ($oldSlug !== '' && $oldSlug !== $slug) {
        assertPodcastPathsAvailable($projectRoot, $slug);
        foreach (['audios', 'images'] as $kind) {
            $oldPath = $projectRoot . '/' . $kind . '/' . $oldSlug;
            $newPath = $projectRoot . '/' . $kind . '/' . $slug;
            if (is_dir($oldPath) && file_exists($newPath)) {
                throw new RuntimeException(__('No se puede cambiar el directorio porque la ruta de medios de destino ya existe.'));
            }
            if (is_dir($oldPath) && !rename($oldPath, $newPath)) {
                throw new RuntimeException(__('No se pudo mover el directorio de medios del podcast.'));
            }
        }
        $replacements = [
            ['/' . $oldSlug . '/audios/', '/' . $slug . '/audios/'],
            ['/' . $oldSlug . '/images/', '/' . $slug . '/images/'],
        ];
        foreach ($replacements as [$from, $to]) {
            foreach (['audio_url', 'image_url'] as $column) {
                $pdo->prepare("UPDATE episodes SET $column = REPLACE($column, :from, :to) WHERE podcast_id = :podcast_id")
                    ->execute([':from' => $from, ':to' => $to, ':podcast_id' => $podcastId]);
            }
            $pdo->prepare('UPDATE podcast SET image_url = REPLACE(image_url, :from, :to), hero_image_url = REPLACE(hero_image_url, :from, :to) WHERE id = :id')
                ->execute([':from' => $from, ':to' => $to, ':id' => $podcastId]);
            $pdo->prepare('UPDATE pages SET content = REPLACE(content, :from, :to) WHERE podcast_id = :podcast_id')
                ->execute([':from' => $from, ':to' => $to, ':podcast_id' => $podcastId]);
        }
        $pdo->prepare('UPDATE episodes SET link = REPLACE(link, :from, :to) WHERE podcast_id = :podcast_id')
            ->execute([
                ':from' => '/' . $oldSlug . '/',
                ':to' => '/' . $slug . '/',
                ':podcast_id' => $podcastId,
            ]);
    }
    ensurePodcastMediaDirectories($projectRoot, $slug);
    $canonicalBase = extractBaseUrlFromLink((string) ($podcast['link'] ?? '')) ?? requestBaseUrl();
    $pdo->prepare("UPDATE podcast SET slug = :slug, link = :link, updated_at = datetime('now') WHERE id = :id")
        ->execute([':slug' => $slug, ':link' => rtrim($canonicalBase, '/') . '/' . $slug, ':id' => $podcastId]);
}

function migrateLegacyPodcastMedia(PDO $pdo, string $projectRoot, int $podcastId, string $slug): void
{
    ensurePodcastMediaDirectories($projectRoot, $slug);
    $moves = [];
    $sources = [
        [$projectRoot . '/audios', $projectRoot . '/audios/' . $slug],
        [$projectRoot . '/images', $projectRoot . '/images/' . $slug],
        [$projectRoot . '/images/generated', $projectRoot . '/images/' . $slug . '/generated'],
    ];
    try {
        foreach ($sources as [$sourceDir, $targetDir]) {
            foreach (@scandir($sourceDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === $slug) {
                    continue;
                }
                $source = $sourceDir . '/' . $entry;
                $target = $targetDir . '/' . $entry;
                if (!is_file($source)) {
                    continue;
                }
                if (file_exists($target) || !rename($source, $target)) {
                    throw new RuntimeException(__('No se pudo mover el directorio de medios del podcast.'));
                }
                $moves[] = [$source, $target];
            }
        }
    } catch (Throwable $e) {
        foreach (array_reverse($moves) as [$source, $target]) {
            if (is_file($target)) {
                @rename($target, $source);
            }
        }
        removePodcastDirectory($projectRoot . '/audios/' . $slug);
        removePodcastDirectory($projectRoot . '/images/' . $slug);
        throw $e;
    }

    $replacements = [
        ['/audios/', '/' . $slug . '/audios/'],
        ['/images/', '/' . $slug . '/images/'],
    ];
    foreach ($replacements as [$from, $to]) {
        $pdo->prepare('UPDATE episodes SET audio_url = REPLACE(audio_url, :from, :to), image_url = REPLACE(image_url, :from, :to) WHERE podcast_id = :podcast_id')
            ->execute([':from' => $from, ':to' => $to, ':podcast_id' => $podcastId]);
        $pdo->prepare('UPDATE podcast SET image_url = REPLACE(image_url, :from, :to), hero_image_url = REPLACE(hero_image_url, :from, :to) WHERE id = :podcast_id')
            ->execute([':from' => $from, ':to' => $to, ':podcast_id' => $podcastId]);
        $pdo->prepare('UPDATE pages SET content = REPLACE(content, :from, :to) WHERE podcast_id = :podcast_id')
            ->execute([':from' => $from, ':to' => $to, ':podcast_id' => $podcastId]);
    }
}

/** @return array{backup_file:string} */
function deletePodcastWithBackup(PDO $pdo, string $dbPath, string $projectRoot, int $podcastId, string $confirmation): array
{
    $podcast = podcastById($pdo, $podcastId);
    if ($podcast === null) {
        throw new RuntimeException(__('El podcast no existe.'));
    }
    if (!hash_equals((string) $podcast['title'], trim($confirmation))) {
        throw new RuntimeException(__('Escribe exactamente el título del podcast para confirmar el borrado.'));
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM podcast')->fetchColumn();
    if ($count <= 1) {
        throw new RuntimeException(__('No se puede borrar el único podcast de la instalación.'));
    }
    if (!class_exists('ZipArchive') || !class_exists('SQLite3')) {
        throw new RuntimeException(__('No se puede borrar sin crear antes una copia consistente porque ZipArchive o SQLite3 no están disponibles.'));
    }
    $backupDir = $projectRoot . '/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
        throw new RuntimeException(__('No se pudo crear el directorio de backups.'));
    }
    $safeSlug = (string) ($podcast['slug'] ?: 'podcast-' . $podcastId);
    $fileName = 'podcast-' . $safeSlug . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    $zipPath = $backupDir . '/' . $fileName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException(__('No se pudo crear la copia de seguridad del podcast.'));
    }
    $snapshotPath = $backupDir . '/.' . $fileName . '.sqlite.tmp';
    $sourceDb = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $snapshotDb = new SQLite3($snapshotPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $snapshotOk = $sourceDb->backup($snapshotDb);
    $sourceDb->close();
    $snapshotDb->close();
    if (!$snapshotOk || !is_file($snapshotPath)) {
        @unlink($snapshotPath);
        $zip->close();
        @unlink($zipPath);
        throw new RuntimeException(__('No se pudo crear una copia consistente de la base de datos.'));
    }
    $zip->addFile($snapshotPath, 'podcast.sqlite');
    foreach (['audios', 'images'] as $kind) {
        addDirectoryToZip($zip, $projectRoot . '/' . $kind . '/' . $safeSlug, $kind . '/' . $safeSlug);
    }
    $zipClosed = $zip->close();
    $snapshotRemoved = @unlink($snapshotPath);
    if (!$zipClosed || !$snapshotRemoved || is_file($snapshotPath)) {
        @unlink($zipPath);
        throw new RuntimeException(__('No se pudo finalizar la copia de seguridad del podcast.'));
    }

    $pdo->beginTransaction();
    try {
        foreach (['estadisticas', 'estadisticas_mensuales', 'estadisticas_anuales', 'api_tokens', 'social'] as $table) {
            $pdo->prepare('DELETE FROM ' . $table . ' WHERE podcast_id = :id')->execute([':id' => $podcastId]);
        }
        $pdo->prepare('DELETE FROM pages WHERE podcast_id = :id AND parent_id IS NOT NULL')->execute([':id' => $podcastId]);
        $pdo->prepare('DELETE FROM pages WHERE podcast_id = :id')->execute([':id' => $podcastId]);
        $pdo->prepare('DELETE FROM episodes WHERE podcast_id = :id')->execute([':id' => $podcastId]);
        $pdo->prepare('DELETE FROM podcast WHERE id = :id')->execute([':id' => $podcastId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($zipPath);
        throw $e;
    }
    foreach (['audios', 'images'] as $kind) {
        removePodcastDirectory($projectRoot . '/' . $kind . '/' . $safeSlug);
    }
    $_SESSION['podcast_backup_file'] = $fileName;
    return ['backup_file' => $fileName];
}

function addDirectoryToZip(ZipArchive $zip, string $directory, string $prefix): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $zip->addFile($file->getPathname(), $prefix . '/' . $relative);
        }
    }
}

function removePodcastDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}
