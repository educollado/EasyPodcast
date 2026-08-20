<?php

declare(strict_types=1);

require_once __DIR__ . '/podcast_context.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/upload_service.php';
require_once __DIR__ . '/admin_theme.php';

/** @return array{podcasts:array,primary_podcast:?array,settings:array,error:string,notice:string,backup_file:string,backup_files:array<int,string>} */
function loadPodcastsManagementData(string $dbPath, string $projectRoot): array
{
    $error = '';
    $notice = '';
    $backupFile = '';
    $backupFiles = [];
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
                    throw $e;
                }
                clearWebCache();
                $notice = __('Podcast creado correctamente.');
            } elseif ($action === 'save_settings') {
                $settingsResult = saveMultipodcastSettings($pdo, $dbPath, $projectRoot);
                i18n_load($settingsResult['summary_language']);
                $backupFiles = $settingsResult['backup_files'];
                if ($backupFiles !== []) {
                    $_SESSION['podcast_backup_files'] = $backupFiles;
                }
                clearWebCache();
                $notice = __('Configuración multipodcast guardada correctamente.');
            } elseif ($action === 'rename_slug') {
                renamePodcastSlug($pdo, $projectRoot, (int) ($_POST['podcast_id'] ?? 0), (string) ($_POST['slug'] ?? ''));
                clearWebCache();
                $notice = __('Directorio del podcast actualizado correctamente.');
            } elseif ($action === 'set_primary') {
                setPrimaryPodcast($pdo, (int) ($_POST['podcast_id'] ?? 0));
                clearWebCache();
                $notice = __('Podcast principal actualizado correctamente.');
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
    $primaryPodcast = primaryPodcast($pdo);
    $settings = loadAppSettings($pdo);
    return compact('podcasts', 'settings', 'error', 'notice') + [
        'primary_podcast' => $primaryPodcast,
        'backup_file' => $backupFile,
        'backup_files' => $backupFiles,
    ];
}

function setPrimaryPodcast(PDO $pdo, int $podcastId): void
{
    if (podcastById($pdo, $podcastId) === null) {
        throw new RuntimeException(__('El podcast no existe.'));
    }
    $stmt = $pdo->prepare('UPDATE app_settings SET primary_podcast_id = :podcast_id WHERE id = 1');
    $stmt->execute([':podcast_id' => $podcastId]);
}

function requestBaseUrl(): string
{
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $scheme . '://' . ($host !== '' ? $host : 'localhost');
}

function ensurePodcastMediaDirectories(string $projectRoot, string $slug): void
{
    foreach (['audios', 'images', 'images/generated'] as $relative) {
        $path = rtrim($projectRoot, '/') . '/' . $relative;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(__('No se pudo crear el directorio de medios del podcast.'));
        }
    }
}

function assertPodcastPathsAvailable(string $projectRoot, string $slug): void
{
    foreach ([$projectRoot . '/' . $slug] as $path) {
        if (file_exists($path)) {
            throw new RuntimeException(__('Ese directorio está ocupado en el servidor. Elige otro.'));
        }
    }
}

/** @return array{backup_files:array<int,string>,summary_language:string} */
function saveMultipodcastSettings(PDO $pdo, string $dbPath, string $projectRoot): array
{
    $enabled = isset($_POST['multipodcast_enabled']) ? 1 : 0;
    $homepageMode = (string) ($_POST['homepage_mode'] ?? '');
    if (!in_array($homepageMode, ['summary', 'podcast'], true)) {
        // Compatibilidad con formularios anteriores al selector explícito.
        $homepageMode = ($_POST['homepage_podcast_id'] ?? '') !== '' ? 'podcast' : 'summary';
    }
    $homepageId = $homepageMode === 'podcast' ? (int) ($_POST['homepage_podcast_id'] ?? 0) : null;
    $currentSettings = loadAppSettings($pdo);
    $summaryHeroImageUrl = $currentSettings['summary_hero_image_url'];
    $summaryTitle = $currentSettings['summary_title'];
    $summarySubtitle = $currentSettings['summary_subtitle'];
    $summaryTheme = $currentSettings['summary_theme'];
    $summaryLanguage = trim((string) ($_POST['summary_language'] ?? $currentSettings['summary_language']));
    if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $summaryLanguage)
        || !is_file(dirname(__DIR__) . '/locale/' . $summaryLanguage . '.po')) {
        throw new RuntimeException(__('El idioma de Multipodcast no es válido.'));
    }
    $backupFiles = [];
    $wasEnabled = $currentSettings['multipodcast_enabled'] === 1;

    if (!$wasEnabled && $enabled === 1) {
        $primary = primaryPodcast($pdo);
        if ($primary === null) {
            throw new RuntimeException(__('El podcast principal no existe.'));
        }
        $slug = normalizePodcastSlug((string) ($_POST['conversion_slug'] ?? ''));
        $slugError = validatePodcastSlug($slug);
        if ($slugError !== null) {
            throw new RuntimeException($slugError);
        }
        setPrimaryPodcast($pdo, (int) $primary['id']);
        renamePodcastSlug($pdo, $projectRoot, (int) $primary['id'], $slug);
    } elseif ($wasEnabled && $enabled === 0) {
        $backupFiles = deactivateMultipodcast(
            $pdo,
            $dbPath,
            $projectRoot,
            (string) ($_POST['disable_confirm_title'] ?? ''),
            isset($_POST['confirm_disable'])
        );
        $homepageId = (int) (primaryPodcast($pdo)['id'] ?? 0) ?: null;
    }
    if ($homepageMode === 'podcast' && ($homepageId <= 0 || podcastById($pdo, $homepageId) === null)) {
        throw new RuntimeException(__('El podcast elegido para la portada no existe.'));
    }

    if ($homepageId === null) {
        $summaryTitle = trim((string) ($_POST['summary_title'] ?? ''));
        $summarySubtitle = trim((string) ($_POST['summary_subtitle'] ?? ''));
        $summaryTheme = trim((string) ($_POST['summary_theme'] ?? 'easypodcast'));
        if (!isset(ADMIN_THEMES[$summaryTheme])) {
            throw new RuntimeException(__('El tema del resumen no es válido.'));
        }
        $summaryHeroImageUrl = trim((string) ($_POST['summary_hero_image_url'] ?? ''));
        if ($summaryHeroImageUrl !== '' && filter_var($summaryHeroImageUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException(__('La URL de la imagen del hero no es válida.'));
        }
        $uploadedHero = is_array($_FILES['summary_hero_image_file'] ?? null)
            ? $_FILES['summary_hero_image_file']
            : ['error' => UPLOAD_ERR_NO_FILE];
        $heroResult = handleHeroImageUpload(
            $uploadedHero,
            requestBaseUrl(),
            rtrim($projectRoot, '/') . '/images'
        );
        if ($heroResult['error'] !== null) {
            throw new RuntimeException(__('No se pudo subir la imagen del hero: %s', $heroResult['error']));
        }
        if ($heroResult['url'] !== null) {
            $summaryHeroImageUrl = $heroResult['url'];
        }
    }

    $stmt = $pdo->prepare('UPDATE app_settings SET multipodcast_enabled = :enabled, homepage_podcast_id = :homepage, summary_hero_image_url = :summary_hero, summary_title = :summary_title, summary_subtitle = :summary_subtitle, summary_theme = :summary_theme, summary_language = :summary_language WHERE id = 1');
    $stmt->bindValue(':enabled', $enabled, PDO::PARAM_INT);
    $stmt->bindValue(':homepage', $homepageId, $homepageId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':summary_hero', $summaryHeroImageUrl);
    $stmt->bindValue(':summary_title', $summaryTitle);
    $stmt->bindValue(':summary_subtitle', $summarySubtitle);
    $stmt->bindValue(':summary_theme', $summaryTheme);
    $stmt->bindValue(':summary_language', $summaryLanguage);
    $stmt->execute();
    return ['backup_files' => $backupFiles, 'summary_language' => $summaryLanguage];
}

/** @return array<int,string> */
function deactivateMultipodcast(
    PDO $pdo,
    string $dbPath,
    string $projectRoot,
    string $confirmation,
    bool $confirmed
): array {
    $primary = primaryPodcast($pdo);
    if ($primary === null) {
        throw new RuntimeException(__('El podcast principal no existe.'));
    }
    if (!$confirmed || !hash_equals((string) $primary['title'], trim($confirmation))) {
        throw new RuntimeException(__('Marca la confirmación y escribe exactamente el título del podcast principal para desactivar Multipodcast.'));
    }

    $slug = trim((string) ($primary['slug'] ?? ''));
    $secondaryStmt = $pdo->prepare('SELECT id, title FROM podcast WHERE id != :primary_id ORDER BY id ASC');
    $secondaryStmt->execute([':primary_id' => (int) $primary['id']]);
    $secondaryPodcasts = $secondaryStmt->fetchAll();
    $backupFiles = [];

    foreach ($secondaryPodcasts as $secondary) {
        $result = deletePodcastWithBackup(
            $pdo,
            $dbPath,
            $projectRoot,
            (int) $secondary['id'],
            (string) $secondary['title']
        );
        $backupFiles[] = $result['backup_file'];
    }

    try {
        $pdo->beginTransaction();
        if ($slug !== '') {
            $pdo->prepare('UPDATE episodes SET link = REPLACE(link, :from, :to) WHERE podcast_id = :podcast_id')
                ->execute([':from' => '/' . $slug . '/', ':to' => '/', ':podcast_id' => (int) $primary['id']]);
        }
        $canonicalBase = extractBaseUrlFromLink((string) ($primary['link'] ?? '')) ?? requestBaseUrl();
        $pdo->prepare("UPDATE podcast SET slug = NULL, link = :link, updated_at = datetime('now') WHERE id = :id")
            ->execute([':link' => rtrim($canonicalBase, '/'), ':id' => (int) $primary['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['active_podcast_id'] = (int) $primary['id'];
    }
    return $backupFiles;
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
    }
    if ($oldSlug !== '' && $oldSlug !== $slug) {
        assertPodcastPathsAvailable($projectRoot, $slug);
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
    $assignedUsers = $pdo->prepare('SELECT COUNT(*) FROM management_podcasts WHERE podcast_id = :podcast_id');
    $assignedUsers->execute([':podcast_id' => $podcastId]);
    if ((int) $assignedUsers->fetchColumn() > 0) {
        throw new RuntimeException(__('No se puede borrar un podcast que tiene usuarios asignados. Reasígnalos primero.'));
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM podcast')->fetchColumn();
    if ($count <= 1) {
        throw new RuntimeException(__('No se puede borrar el único podcast de la instalación.'));
    }
    $replacementStmt = $pdo->prepare('SELECT id FROM podcast WHERE id != :id ORDER BY id ASC LIMIT 1');
    $replacementStmt->execute([':id' => $podcastId]);
    $replacementPodcastId = (int) $replacementStmt->fetchColumn();
    $deletingPrimaryPodcast = loadAppSettings($pdo)['primary_podcast_id'] === $podcastId;
    $mediaCandidates = podcastMediaBasenames($pdo, $podcastId);
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
    foreach ($mediaCandidates as $kind => $basenames) {
        foreach ($basenames as $basename) {
            foreach (podcastMediaCandidatePaths($projectRoot, $kind, $safeSlug, $basename) as $mediaPath) {
                $zip->addFile($mediaPath, $kind . '/' . basename($mediaPath));
                break;
            }
        }
    }
    $zipClosed = $zip->close();
    $snapshotRemoved = @unlink($snapshotPath);
    if (!$zipClosed || !$snapshotRemoved || is_file($snapshotPath)) {
        @unlink($zipPath);
        throw new RuntimeException(__('No se pudo finalizar la copia de seguridad del podcast.'));
    }

    $pdo->beginTransaction();
    try {
        if ($deletingPrimaryPodcast) {
            $pdo->prepare('UPDATE app_settings SET primary_podcast_id = :replacement WHERE id = 1')
                ->execute([':replacement' => $replacementPodcastId]);
        }
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
    $remainingMedia = allPodcastMediaBasenames($pdo);
    foreach ($mediaCandidates as $kind => $basenames) {
        foreach ($basenames as $basename) {
            if (isset($remainingMedia[$kind][$basename])) {
                continue;
            }
            foreach (podcastMediaCandidatePaths($projectRoot, $kind, $safeSlug, $basename) as $mediaPath) {
                @unlink($mediaPath);
            }
        }
    }
    $_SESSION['podcast_backup_file'] = $fileName;
    return ['backup_file' => $fileName];
}

/** @return array{audios:array<string,string>,images:array<string,string>} */
function podcastMediaBasenames(PDO $pdo, int $podcastId): array
{
    $media = ['audios' => [], 'images' => []];
    $episodeStmt = $pdo->prepare('SELECT audio_url, image_url, content FROM episodes WHERE podcast_id = :podcast_id');
    $episodeStmt->execute([':podcast_id' => $podcastId]);
    foreach ($episodeStmt->fetchAll() as $episode) {
        addMediaUrlBasename($media['audios'], (string) ($episode['audio_url'] ?? ''), 'audios');
        addMediaUrlBasename($media['images'], (string) ($episode['image_url'] ?? ''), 'images');
        addMediaBasenamesFromHtml($media['images'], (string) ($episode['content'] ?? ''), 'images');
    }
    $podcast = podcastById($pdo, $podcastId) ?? [];
    addMediaUrlBasename($media['images'], (string) ($podcast['image_url'] ?? ''), 'images');
    addMediaUrlBasename($media['images'], (string) ($podcast['hero_image_url'] ?? ''), 'images');
    $pageStmt = $pdo->prepare('SELECT content FROM pages WHERE podcast_id = :podcast_id');
    $pageStmt->execute([':podcast_id' => $podcastId]);
    foreach ($pageStmt->fetchAll(PDO::FETCH_COLUMN) as $content) {
        addMediaBasenamesFromHtml($media['images'], (string) $content, 'images');
    }
    return $media;
}

/** @param array<string,string> $basenames */
function addMediaUrlBasename(array &$basenames, string $url, string $kind): void
{
    $path = parse_url(trim($url), PHP_URL_PATH);
    if (!is_string($path) || !str_contains($path, '/' . $kind . '/')) {
        return;
    }
    $basename = basename(rawurldecode($path));
    if ($basename !== '' && $basename !== '.' && $basename !== '..') {
        $basenames[$basename] = $basename;
    }
}

/** @param array<string,string> $basenames */
function addMediaBasenamesFromHtml(array &$basenames, string $html, string $kind): void
{
    if (preg_match_all('#/(?:[a-z0-9-]+/)?' . preg_quote($kind, '#') . '/([^"\'<>?\s/]+)#i', $html, $matches)) {
        foreach ($matches[1] as $encodedName) {
            $basename = basename(rawurldecode((string) $encodedName));
            if ($basename !== '' && $basename !== '.' && $basename !== '..') {
                $basenames[$basename] = $basename;
            }
        }
    }
}

/** @return array{audios:array<string,true>,images:array<string,true>} */
function allPodcastMediaBasenames(PDO $pdo): array
{
    $all = ['audios' => [], 'images' => []];
    $podcastIds = $pdo->query('SELECT id FROM podcast')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($podcastIds as $podcastId) {
        $podcastMedia = podcastMediaBasenames($pdo, (int) $podcastId);
        foreach (['audios', 'images'] as $kind) {
            foreach ($podcastMedia[$kind] as $basename) {
                $all[$kind][$basename] = true;
            }
        }
    }
    addMediaUrlBasename($all['images'], loadAppSettings($pdo)['summary_hero_image_url'], 'images');
    return $all;
}

/** @return array<int,string> */
function podcastMediaCandidatePaths(string $projectRoot, string $kind, string $slug, string $basename): array
{
    $safeBasename = basename($basename);
    $paths = [rtrim($projectRoot, '/') . '/' . $kind . '/' . $safeBasename];
    if ($slug !== '') {
        $paths[] = rtrim($projectRoot, '/') . '/' . $kind . '/' . $slug . '/' . $safeBasename;
    }
    return array_values(array_filter($paths, static fn (string $path): bool => is_file($path) && !is_link($path)));
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
