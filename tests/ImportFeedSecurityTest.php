<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/import_feed_handler.php';

test('buildCurlResolveEntry fija IPv4 y puerto HTTPS', function () {
    assert_eq('example.com:443:93.184.216.34', buildCurlResolveEntry(
        'https://example.com/feed.xml',
        ['93.184.216.34']
    ));
});

test('buildCurlResolveEntry encierra IPv6 entre corchetes', function () {
    assert_eq('example.com:8443:[2606:2800:220:1:248:1893:25c8:1946]', buildCurlResolveEntry(
        'https://example.com:8443/feed.xml',
        ['2606:2800:220:1:248:1893:25c8:1946']
    ));
});

test('buildCurlResolveEntry admite host IPv6 literal sin ambigüedad', function () {
    assert_eq('[2606:4700:4700::1111]:443:[2606:4700:4700::1111]', buildCurlResolveEntry(
        'https://[2606:4700:4700::1111]/feed.xml',
        ['2606:4700:4700::1111']
    ));
});

test('validateRemoteFetchUrl devuelve solo IP pública validada', function () {
    $result = validateRemoteFetchUrl('https://8.8.8.8/feed.xml');
    assert_true($result['ok']);
    assert_eq(['8.8.8.8'], $result['ips']);
});

test('validateRemoteFetchUrl acepta IPv6 pública literal', function () {
    $result = validateRemoteFetchUrl('https://[2606:4700:4700::1111]/feed.xml');
    assert_true($result['ok']);
    assert_eq(['2606:4700:4700::1111'], $result['ips']);
});

test('validateRemoteFetchUrl bloquea loopback', function () {
    $result = validateRemoteFetchUrl('http://127.0.0.1/private');
    assert_true(!$result['ok']);
    assert_eq([], $result['ips']);
});

test('finalizeDownloadedFile sustituye una extensión remota ejecutable', function () {
    $dir = sys_get_temp_dir() . '/ep_download_security_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $oldPath = $dir . '/payload-abc.download';
    file_put_contents($oldPath, "\xFF\xD8\xFFtest");

    try {
        $result = finalizeDownloadedFile([
            'localPath' => $oldPath,
            'localUrl' => 'https://example.com/images/payload-abc.download',
            'mime' => 'image/jpeg',
            'size' => 7,
            'error' => null,
        ], 'jpg');

        assert_eq(null, $result['error']);
        assert_true(str_ends_with($result['localPath'], '.jpg'));
        assert_true(str_ends_with($result['localUrl'], '.jpg'));
        assert_true(!is_file($oldPath));
        assert_true(is_file($result['localPath']));
    } finally {
        foreach ((array) glob($dir . '/*') as $path) {
            @unlink($path);
        }
        @rmdir($dir);
    }
});
