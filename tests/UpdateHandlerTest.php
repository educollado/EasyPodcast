<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/update_handler.php';

test('parseLatestReleaseData localiza paquete y checksum exactos', function () {
    $result = parseLatestReleaseData([
        'tag_name' => 'v1.2.3',
        'assets' => [
            [
                'name' => 'EasyPodcast-1.2.3.tar.gz',
                'browser_download_url' => 'https://github.com/educollado/EasyPodcast/releases/download/v1.2.3/EasyPodcast-1.2.3.tar.gz',
            ],
            [
                'name' => 'EasyPodcast-1.2.3.tar.gz.sha256',
                'browser_download_url' => 'https://github.com/educollado/EasyPodcast/releases/download/v1.2.3/EasyPodcast-1.2.3.tar.gz.sha256',
            ],
        ],
    ]);

    assert_eq('1.2.3', $result['version']);
    assert_eq('', $result['error']);
    assert_contains('EasyPodcast-1.2.3.tar.gz', $result['tar_url']);
    assert_contains('EasyPodcast-1.2.3.tar.gz.sha256', $result['checksum_url']);
});

test('parseLatestReleaseData rechaza release sin checksum', function () {
    $result = parseLatestReleaseData([
        'tag_name' => 'v1.2.3',
        'tarball_url' => 'https://api.github.com/repos/educollado/EasyPodcast/tarball/v1.2.3',
        'assets' => [[
            'name' => 'EasyPodcast-1.2.3.tar.gz',
            'browser_download_url' => 'https://github.com/educollado/EasyPodcast/releases/download/v1.2.3/EasyPodcast-1.2.3.tar.gz',
        ]],
    ]);

    assert_contains('checksum SHA-256', $result['error']);
    assert_eq('', $result['tar_url']);
    assert_eq('', $result['checksum_url']);
});

test('parseLatestReleaseData no usa el tarball no verificable de GitHub', function () {
    $result = parseLatestReleaseData([
        'tag_name' => 'v1.2.3',
        'tarball_url' => 'https://api.github.com/repos/educollado/EasyPodcast/tarball/v1.2.3',
        'assets' => [],
    ]);

    assert_contains('paquete verificable', $result['error']);
    assert_eq('', $result['tar_url']);
});

test('parseLatestReleaseData rechaza etiquetas no válidas', function () {
    $result = parseLatestReleaseData(['tag_name' => '../1.2.3', 'assets' => []]);

    assert_contains('Versión no reconocida', $result['error']);
});

test('parseSha256Checksum acepta el formato de sha256sum', function () {
    $hash = str_repeat('A', 64);
    $result = parseSha256Checksum($hash . "  EasyPodcast-1.2.3.tar.gz\n", 'EasyPodcast-1.2.3.tar.gz');

    assert_eq(strtolower($hash), $result);
});

test('parseSha256Checksum rechaza un nombre de paquete distinto', function () {
    $hash = str_repeat('a', 64);

    assert_null(parseSha256Checksum($hash . '  otro.tar.gz', 'EasyPodcast-1.2.3.tar.gz'));
});

test('parseSha256Checksum rechaza hashes mal formados', function () {
    assert_null(parseSha256Checksum('1234  EasyPodcast-1.2.3.tar.gz', 'EasyPodcast-1.2.3.tar.gz'));
});

test('archiveMatchesSha256 detecta paquetes alterados', function () {
    $path = tempnam(sys_get_temp_dir(), 'ep_checksum_test_');
    assert_true(is_string($path));

    try {
        file_put_contents($path, 'paquete original');
        assert_true(archiveMatchesSha256($path, hash('sha256', 'paquete original')));
        assert_eq(false, archiveMatchesSha256($path, hash('sha256', 'paquete manipulado')));
    } finally {
        @unlink($path);
    }
});

test('isAllowedGithubDownloadUrl valida host y HTTPS estrictamente', function () {
    assert_true(isAllowedGithubDownloadUrl(
        'https://github.com/educollado/EasyPodcast/releases/download/v1.2.3/EasyPodcast-1.2.3.tar.gz'
    ));
    assert_eq(false, isAllowedGithubDownloadUrl('http://github.com/archivo.tar.gz'));
    assert_eq(false, isAllowedGithubDownloadUrl('https://github.com.evil.example/archivo.tar.gz'));
    assert_eq(false, isAllowedGithubDownloadUrl('https://usuario@github.com/archivo.tar.gz'));
});

test('checksumMatchesArchiveUrl exige mismo directorio y nombre asociado', function () {
    $archive = 'https://github.com/educollado/EasyPodcast/releases/download/v1.2.3/EasyPodcast-1.2.3.tar.gz';
    $checksum = $archive . '.sha256';

    assert_true(checksumMatchesArchiveUrl($archive, $checksum));
    assert_eq(false, checksumMatchesArchiveUrl($archive, $checksum . '.txt'));
    assert_eq(false, checksumMatchesArchiveUrl(
        $archive,
        'https://github.com/otro/proyecto/releases/download/v1.2.3/EasyPodcast-1.2.3.tar.gz.sha256'
    ));
});
