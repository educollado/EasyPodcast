<?php

declare(strict_types=1);

// Ruta absoluta del directorio de caché pública.
function cacheDirectoryPath(): string
{
    return dirname(__DIR__) . '/cache';
}

// Crea el directorio de caché si no existe.
function ensureCacheDirectory(): bool
{
    $dir = cacheDirectoryPath();
    if (is_dir($dir)) {
        return true;
    }

    return mkdir($dir, 0755, true) || is_dir($dir);
}

// Lee si la caché está habilitada en podcast.cache_enabled.
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

        $value = $pdo->query('SELECT cache_enabled FROM podcast ORDER BY id ASC LIMIT 1')->fetchColumn();
        return ((int) $value) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

// Genera ruta de caché por host + request URI.
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

// Sirve caché si aplica para request pública GET.
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

// Persiste salida en caché para la request actual cuando está habilitada.
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

// Borra todos los archivos cacheados.
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
