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

test('buildAdminUpdateStatus detecta versiones nuevas y descarta valores inválidos', function () {
    assert_true(buildAdminUpdateStatus('9.9.9')['available']);
    assert_eq(false, buildAdminUpdateStatus(APP_VERSION)['available']);
    assert_eq(['available' => false, 'version' => ''], buildAdminUpdateStatus('<script>'));
});

test('loadDailyAdminUpdateStatus consulta GitHub solo una vez por día', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-update-check-');
    assert_true(is_string($dbPath));
    $calls = 0;
    $fetch = static function () use (&$calls): array {
        $calls++;
        return [
            'version' => '9.9.9',
            'tar_url' => '',
            'checksum_url' => '',
            'error' => '',
        ];
    };

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->exec(
            'CREATE TABLE podcast (
                id INTEGER PRIMARY KEY,
                last_update_check_date TEXT,
                latest_version_checked TEXT
            )'
        );
        $pdo->exec('INSERT INTO podcast (id) VALUES (1)');
        $pdo = null;

        $first = loadDailyAdminUpdateStatus($dbPath, $fetch, '2026-08-19');
        $second = loadDailyAdminUpdateStatus($dbPath, $fetch, '2026-08-19');
        assert_true($first['available']);
        assert_true($second['available']);
        assert_eq(1, $calls);

        loadDailyAdminUpdateStatus($dbPath, $fetch, '2026-08-20');
        assert_eq(2, $calls);
    } finally {
        @unlink($dbPath);
    }
});

test('aviso de actualización está traducido en todos los idiomas soportados', function () {
    $messages = [
        'Hay una nueva versión de EasyPodcast disponible:',
        'Actualizar ahora',
    ];
    $localeFiles = glob(__DIR__ . '/../locale/*.po') ?: [];
    assert_eq(8, count($localeFiles));

    foreach ($localeFiles as $localeFile) {
        $translations = i18n_parse_po($localeFile);
        foreach ($messages as $message) {
            assert_true(
                isset($translations[$message]) && $translations[$message] !== '',
                basename($localeFile) . ' no traduce: ' . $message
            );
        }
    }
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
