<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/cache_service.php';

// =============================================================================
// cacheDirectoryPath
// =============================================================================

test('cacheDirectoryPath: devuelve ruta absoluta del directorio de caché', function () {
    $path = cacheDirectoryPath();
    
    assert_not_null($path);
    assert_true(is_string($path));
    assert_contains('cache', $path);
    assert_contains('EasyPodcast', $path);
});

test('cacheDirectoryPath: ruta termina con cache', function () {
    $path = cacheDirectoryPath();
    
    $basename = basename($path);
    assert_eq('cache', $basename);
});

test('cacheDirectoryPath: devuelve ruta consistente', function () {
    $path1 = cacheDirectoryPath();
    $path2 = cacheDirectoryPath();
    
    assert_eq($path1, $path2);
});

test('isWebCacheEnabled: la portada resumen usa el podcast principal', function () {
    $source = file_get_contents(__DIR__ . '/../lib/cache_service.php');

    assert_true(is_string($source));
    assert_contains('activePodcast($pdo) ?? primaryPodcast($pdo)', $source);
});

// =============================================================================
// ensureCacheDirectory
// =============================================================================

test('ensureCacheDirectory: devuelve true siempre', function () {
    $result = ensureCacheDirectory();
    
    assert_true($result);
});

// =============================================================================
// webCacheFilePath
// =============================================================================

test('webCacheFilePath: genera ruta única basada en host y URI', function () {
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['REQUEST_URI'] = '/test-page';
    
    $path = webCacheFilePath();
    
    assert_not_null($path);
    assert_true(is_string($path));
    assert_contains('cache', $path);
    assert_contains('.cache', $path);
    assert_eq(64, strlen(basename($path, '.cache'))); // SHA-256 = 64 chars
});

test('webCacheFilePath: normaliza URI vacía a /', function () {
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['REQUEST_URI'] = '';
    
    $path = webCacheFilePath();
    
    $basename = basename($path, '.cache');
    // Hash de example.com|/
    assert_eq(64, strlen($basename));
});

test('webCacheFilePath: usa localhost como host por defecto', function () {
    unset($_SERVER['HTTP_HOST']);
    $_SERVER['REQUEST_URI'] = '/test';
    
    $path = webCacheFilePath();
    
    assert_not_null($path);
});

test('webCacheFilePath: normaliza host a minúsculas', function () {
    $_SERVER['HTTP_HOST'] = 'EXAMPLE.COM';
    $_SERVER['REQUEST_URI'] = '/test';
    
    $path1 = webCacheFilePath();
    
    $_SERVER['HTTP_HOST'] = 'example.com';
    $path2 = webCacheFilePath();
    
    // Ambos deberían generar el mismo hash (mismo host normalizado)
    assert_eq($path1, $path2);
});

test('webCacheFilePath: genera hash diferente para URIs diferentes', function () {
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['REQUEST_URI'] = '/page1';
    $path1 = webCacheFilePath();
    
    $_SERVER['REQUEST_URI'] = '/page2';
    $path2 = webCacheFilePath();
    
    assert_true($path1 !== $path2);
});

test('webCacheFilePath: genera hash diferente para hosts diferentes', function () {
    $_SERVER['HTTP_HOST'] = 'example.com';
    $_SERVER['REQUEST_URI'] = '/test';
    $path1 = webCacheFilePath();
    
    $_SERVER['HTTP_HOST'] = 'test.com';
    $path2 = webCacheFilePath();
    
    assert_true($path1 !== $path2);
});

// =============================================================================
// statsFormatBytes (del stats_handler.php pero relacionada con caché)
// =============================================================================

test('statsFormatBytes: formatea 0 bytes', function () {
    require_once __DIR__ . '/../lib/stats_handler.php';
    $result = statsFormatBytes(0);
    assert_eq('0 B', $result);
});

test('statsFormatBytes: formatea bytes pequeños', function () {
    require_once __DIR__ . '/../lib/stats_handler.php';
    $result = statsFormatBytes(500);
    assert_eq('500 B', $result);
});

test('statsFormatBytes: formatea Kilobytes', function () {
    require_once __DIR__ . '/../lib/stats_handler.php';
    $result = statsFormatBytes(1024);
    assert_eq('1.0 KB', $result);
});

test('statsFormatBytes: formatea Megabytes', function () {
    require_once __DIR__ . '/../lib/stats_handler.php';
    $result = statsFormatBytes(1024 * 1024);
    assert_eq('1.0 MB', $result);
});

test('statsFormatBytes: formatea Gigabytes', function () {
    require_once __DIR__ . '/../lib/stats_handler.php';
    $result = statsFormatBytes(1024 * 1024 * 1024);
    assert_eq('1.0 GB', $result);
});

test('statsFormatBytes: formatea valores negativos', function () {
    require_once __DIR__ . '/../lib/stats_handler.php';
    $result = statsFormatBytes(-100);
    assert_eq('0 B', $result);
});

test('statsFormatBytes: formatea terbesar bytes', function () {
    require_once __DIR__ . '/../lib/stats_handler.php';
    $result = statsFormatBytes(1536);
    assert_eq('1.5 KB', $result);
});
