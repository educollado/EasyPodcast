<?php

declare(strict_types=1);

require_once __DIR__ . '/podcast_context.php';

/**
 * Devuelve la ruta absoluta del directorio de caché pública (<raíz>/cache).
 */
function cacheDirectoryPath(): string
{
    return dirname(__DIR__) . '/cache';
}

/**
 * Crea el directorio de caché si no existe todavía.
 * Devuelve true si el directorio existe o se creó correctamente.
 */
function ensureCacheDirectory(): bool
{
    $dir = cacheDirectoryPath();
    if (is_dir($dir)) {
        return true;
    }

    return mkdir($dir, 0755, true) || is_dir($dir);
}

/**
 * Consulta si la caché web está habilitada en podcast.cache_enabled.
 * Comprueba la existencia de la tabla y la columna antes de leer el valor.
 * Devuelve false si hay cualquier error de BD.
 */
function isWebCacheEnabled(string $dbPath): bool
{
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $tableExists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'podcast' LIMIT 1")
            ->fetchColumn();
        if (!$tableExists) {
            return false;
        }

        $columns = $pdo->query('PRAGMA table_info(podcast)')->fetchAll();
        $hasCacheEnabled = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'cache_enabled') {
                $hasCacheEnabled = true;
                break;
            }
        }
        if (!$hasCacheEnabled) {
            return false;
        }

        // En la portada-resumen no hay podcast activo: su caché sigue la
        // configuración del podcast principal de la instalación.
        $podcast = activePodcast($pdo) ?? primaryPodcast($pdo);
        $value = $podcast['cache_enabled'] ?? 0;
        return ((int) $value) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Genera la ruta de fichero de caché único para la request actual.
 * La clave es un SHA-256 de host + REQUEST_URI para evitar colisiones entre páginas.
 */
function webCacheFilePath(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '') {
        $uri = '/';
    }

    $key = $host . '|' . $uri;
    return cacheDirectoryPath() . '/' . hash('sha256', $key) . '.cache';
}

/**
 * Sirve la respuesta cacheada si la request es GET y la caché está habilitada.
 * Añade la cabecera X-EasyPodcast-Cache: HIT para identificar hits de caché.
 * Devuelve true si se sirvió desde caché (el caller debe hacer exit después).
 */
function tryServeWebCache(string $dbPath, string $contentType): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }
    if (!isWebCacheEnabled($dbPath)) {
        return false;
    }

    $cachePath = webCacheFilePath();
    if (!is_file($cachePath)) {
        return false;
    }

    $body = @file_get_contents($cachePath);
    if (!is_string($body)) {
        return false;
    }

    if (!headers_sent()) {
        header('Content-Type: ' . $contentType);
        header('X-EasyPodcast-Cache: HIT');
    }
    echo $body;
    return true;
}

/**
 * Persiste el cuerpo de la respuesta en caché para la request actual.
 * Usa escritura atómica (fichero temporal + rename) para evitar lecturas parciales.
 * No almacena respuestas de error (HTTP >= 400) ni peticiones que no sean GET.
 */
function storeWebCache(string $dbPath, string $body): void
{
    if ($body === '' || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;
    }
    $statusCode = (int) http_response_code();
    if ($statusCode >= 400) {
        return;
    }
    if (!isWebCacheEnabled($dbPath)) {
        return;
    }
    if (!ensureCacheDirectory()) {
        return;
    }

    $cachePath = webCacheFilePath();
    try {
        $tmpPath = $cachePath . '.tmp-' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $tmpPath = $cachePath . '.tmp-' . (string) mt_rand(100000, 999999);
    }
    if (@file_put_contents($tmpPath, $body) === false) {
        return;
    }

    @rename($tmpPath, $cachePath);
}

/**
 * Borra todas las variantes de imagen generadas en images/generated/.
 * Devuelve true si todos los ficheros se eliminaron correctamente, false si alguno falló.
 */
function clearImageCache(): bool
{
    $ok = true;
    $imageRoot = dirname(__DIR__) . '/images';
    $directories = [$imageRoot . '/generated'];
    foreach (@glob($imageRoot . '/*/generated', GLOB_ONLYDIR) ?: [] as $scopedDir) {
        $directories[] = $scopedDir;
    }
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (@scandir($dir) ?: [] as $entry) {
            $path = $dir . '/' . $entry;
            if ($entry !== '.' && $entry !== '..' && is_file($path) && !@unlink($path)) {
                $ok = false;
            }
        }
    }

    return $ok;
}

/**
 * Borra todos los ficheros del directorio de caché.
 * Devuelve true si todos los ficheros se eliminaron correctamente, false si alguno falló.
 */
function clearWebCache(): bool
{
    $dir = cacheDirectoryPath();
    if (!is_dir($dir)) {
        return true;
    }

    $ok = true;
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return false;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_file($path) && !@unlink($path)) {
            $ok = false;
        }
    }

    return $ok;
}
