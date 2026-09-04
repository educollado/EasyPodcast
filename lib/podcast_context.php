<?php

declare(strict_types=1);

const RESERVED_PODCAST_SLUGS = [
    'admin', 'api', 'assets', 'audios', 'images', 'cache', 'locale', 'lib', 'tests', 'tools',
    'feed', 'feed.xml', 'sitemap', 'sitemap.xml', 'robots', 'robots.txt', 'search', 'track',
    'index', 'episode', 'page', 'podcast', 'podcasts', 'multipodcast', 'backups', 'update', 'stats', 'login',
    'import-feed', 'media-cleanup', 'pages', 'social', 'users', 'favicon.ico',
];

function openPodcastDatabase(string $dbPath): PDO
{
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/** @return array{multipodcast_enabled:int,homepage_podcast_id:?int,summary_hero_image_url:string,summary_title:string,summary_subtitle:string,summary_theme:string,summary_language:string,primary_podcast_id:?int} */
function loadAppSettings(PDO $pdo): array
{
    $row = $pdo->query('SELECT multipodcast_enabled, homepage_podcast_id, summary_hero_image_url, summary_title, summary_subtitle, summary_theme, summary_language, primary_podcast_id FROM app_settings WHERE id = 1')->fetch();
    return [
        'multipodcast_enabled' => (int) ($row['multipodcast_enabled'] ?? 0),
        'homepage_podcast_id' => isset($row['homepage_podcast_id']) ? (int) $row['homepage_podcast_id'] : null,
        'summary_hero_image_url' => trim((string) ($row['summary_hero_image_url'] ?? '')),
        'summary_title' => trim((string) ($row['summary_title'] ?? '')),
        'summary_subtitle' => trim((string) ($row['summary_subtitle'] ?? '')),
        'summary_theme' => trim((string) ($row['summary_theme'] ?? '')) ?: 'easypodcast',
        'summary_language' => trim((string) ($row['summary_language'] ?? '')) ?: 'es_ES',
        'primary_podcast_id' => isset($row['primary_podcast_id']) ? (int) $row['primary_podcast_id'] : null,
    ];
}

function normalizePodcastSlug(string $value): string
{
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = is_string($ascii) ? $ascii : $value;
    $value = strtolower((string) preg_replace('/[^a-z0-9]+/', '-', $value));
    return trim($value, '-');
}

function validatePodcastSlug(string $value): ?string
{
    if ($value === '' || strlen($value) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
        return __('El directorio debe contener únicamente letras minúsculas, números y guiones.');
    }
    if (in_array($value, RESERVED_PODCAST_SLUGS, true) || preg_match('/^[0-9]{4}$/', $value)) {
        return __('Ese directorio está reservado por la aplicación.');
    }
    return null;
}

function podcastById(PDO $pdo, int $podcastId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM podcast WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $podcastId]);
    return $stmt->fetch() ?: null;
}

function podcastBySlug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM podcast WHERE slug = :slug AND slug != '' LIMIT 1");
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetch() ?: null;
}

function firstPodcast(PDO $pdo): ?array
{
    return $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: null;
}

function primaryPodcast(PDO $pdo): ?array
{
    try {
        $primaryPodcastId = loadAppSettings($pdo)['primary_podcast_id'];
        if ($primaryPodcastId !== null) {
            $podcast = podcastById($pdo, $primaryPodcastId);
            if ($podcast !== null) {
                return $podcast;
            }
        }
    } catch (Throwable $e) {
        // Instalaciones anteriores a Multipodcast conservan el primer podcast.
    }
    return firstPodcast($pdo);
}

/** Resuelve el podcast de un feed: el feed raíz pertenece siempre al principal. */
function resolveFeedPodcast(PDO $pdo, ?string $requestedSlug = null): ?array
{
    if ($requestedSlug !== null && $requestedSlug !== '') {
        return podcastBySlug($pdo, $requestedSlug);
    }
    return primaryPodcast($pdo);
}

/** Resuelve el podcast público. null significa portada agregada multipodcast. */
function resolvePublicPodcast(PDO $pdo, ?string $requestedSlug = null): ?array
{
    $settings = loadAppSettings($pdo);
    if ($settings['multipodcast_enabled'] !== 1) {
        return $settings['primary_podcast_id'] !== null
            ? (podcastById($pdo, $settings['primary_podcast_id']) ?? firstPodcast($pdo))
            : firstPodcast($pdo);
    }
    if ($requestedSlug !== null && $requestedSlug !== '') {
        return podcastBySlug($pdo, $requestedSlug);
    }
    return $settings['homepage_podcast_id'] !== null
        ? podcastById($pdo, $settings['homepage_podcast_id'])
        : null;
}

function resolveAdminPodcast(PDO $pdo, ?string $requestedSlug = null): ?array
{
    if (isset($_SESSION['admin_user']) && (int) ($_SESSION['admin_is_global'] ?? 0) !== 1) {
        $assignedPodcastIds = array_values(array_filter(array_map('intval', (array) ($_SESSION['admin_podcast_ids'] ?? []))));
        if ($requestedSlug !== null && $requestedSlug !== '') {
            $requestedPodcast = podcastBySlug($pdo, $requestedSlug);
            if ($requestedPodcast !== null && in_array((int) $requestedPodcast['id'], $assignedPodcastIds, true)) {
                $_SESSION['active_podcast_id'] = (int) $requestedPodcast['id'];
                return $requestedPodcast;
            }
        }
        $activeId = (int) ($_SESSION['active_podcast_id'] ?? 0);
        if (in_array($activeId, $assignedPodcastIds, true)) {
            return podcastById($pdo, $activeId);
        }
        $firstAssignedId = $assignedPodcastIds[0] ?? 0;
        if ($firstAssignedId > 0) {
            $_SESSION['active_podcast_id'] = $firstAssignedId;
            return podcastById($pdo, $firstAssignedId);
        }
        return null;
    }
    if ($requestedSlug !== null && $requestedSlug !== '') {
        $podcast = podcastBySlug($pdo, $requestedSlug);
        if ($podcast !== null && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['active_podcast_id'] = (int) $podcast['id'];
        }
        return $podcast;
    }
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['active_podcast_id'])) {
        $podcast = podcastById($pdo, (int) $_SESSION['active_podcast_id']);
        if ($podcast !== null) {
            return $podcast;
        }
        unset($_SESSION['active_podcast_id']);
    }
    return primaryPodcast($pdo);
}

function activatePodcastContext(?array $podcast, bool $multipodcastEnabled): void
{
    if ($podcast !== null) {
        $podcast['_multipodcast_enabled'] = $multipodcastEnabled;
    }
    $GLOBALS['_active_podcast'] = $podcast;
    $GLOBALS['_multipodcast_enabled'] = $multipodcastEnabled;
}

function activePodcast(PDO $pdo): ?array
{
    if (array_key_exists('_active_podcast', $GLOBALS)) {
        return is_array($GLOBALS['_active_podcast']) ? $GLOBALS['_active_podcast'] : null;
    }
    return primaryPodcast($pdo);
}

function activePodcastId(PDO $pdo): int
{
    try {
        $podcast = activePodcast($pdo);
        return (int) ($podcast['id'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function multipodcastEnabled(PDO $pdo): bool
{
    if (isset($GLOBALS['_multipodcast_enabled'])) {
        return (bool) $GLOBALS['_multipodcast_enabled'];
    }
    return loadAppSettings($pdo)['multipodcast_enabled'] === 1;
}

function activatePublicPodcastContext(string $dbPath, ?string $requestedSlug = null): ?array
{
    $pdo = openPodcastDatabase($dbPath);
    $settings = loadAppSettings($pdo);
    $podcast = resolvePublicPodcast($pdo, $requestedSlug);
    activatePodcastContext($podcast, $settings['multipodcast_enabled'] === 1);
    return $podcast;
}

function activateAdminPodcastContext(string $dbPath, ?string $requestedSlug = null): ?array
{
    $pdo = openPodcastDatabase($dbPath);
    $settings = loadAppSettings($pdo);
    $podcast = resolveAdminPodcast($pdo, $requestedSlug);
    activatePodcastContext($podcast, $settings['multipodcast_enabled'] === 1);
    return $podcast;
}

function podcastBasePath(array $podcast, bool $multipodcastEnabled = true): string
{
    $slug = trim((string) ($podcast['slug'] ?? ''));
    return $multipodcastEnabled && $slug !== '' ? '/' . rawurlencode($slug) : '';
}

function podcastPath(array $podcast, string $path, bool $multipodcastEnabled = true): string
{
    return podcastBasePath($podcast, $multipodcastEnabled) . '/' . ltrim($path, '/');
}

/** URL del dashboard que conserva explícitamente el podcast administrado. */
function adminPodcastDashboardUrl(?array $podcast, bool $multipodcastEnabled): string
{
    $slug = trim((string) ($podcast['slug'] ?? ''));
    if (!$multipodcastEnabled || $slug === '') {
        return 'admin.php';
    }

    return 'admin.php?podcast=' . rawurlencode($slug) . '&manage=1';
}

function podcastStorageDirectory(string $projectRoot, string $kind, array $podcast, bool $multipodcastEnabled): string
{
    return rtrim($projectRoot, '/') . '/' . $kind;
}
