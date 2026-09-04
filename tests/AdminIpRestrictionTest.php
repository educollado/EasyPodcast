<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin_ip_restriction.php';

test('valida, normaliza y elimina duplicados de IP y rangos de administración', function () {
    $result = parseAdminIpEntries("31.24.120.43\n192.0.64.0/18\n2a01:a940:406:3:0:0:0:1\n2a01:a940:406::/48\n31.24.120.43");

    assert_eq([], $result['invalid']);
    assert_eq([
        '31.24.120.43',
        '192.0.64.0/18',
        '2a01:a940:406:3::1',
        '2a01:a940:406::/48',
    ], $result['entries']);
});

test('rechaza direcciones, prefijos e intentos de inyectar directivas no válidos', function () {
    $result = parseAdminIpEntries("999.1.2.3\n192.0.2.1/33\n2001:db8::1/129\nRequire all granted");

    assert_eq([], $result['entries']);
    assert_eq(['999.1.2.3', '192.0.2.1/33', '2001:db8::1/129', 'Require', 'all', 'granted'], $result['invalid']);
});

test('la confirmación de activación es de un solo uso, caduca y exige la misma lista', function () {
    $oldSession = $_SESSION ?? [];
    try {
        $_SESSION = [];
        $entries = ['192.0.2.10', '2001:db8::/48'];
        prepareAdminIpConfirmation($entries, 1000);
        assert_true(!consumeAdminIpConfirmation(['192.0.2.11'], 1001));

        prepareAdminIpConfirmation($entries, 1000);
        assert_true(consumeAdminIpConfirmation($entries, 1001));
        assert_true(!consumeAdminIpConfirmation($entries, 1001));

        prepareAdminIpConfirmation($entries, 1000);
        assert_true(!consumeAdminIpConfirmation($entries, 1301));
    } finally {
        $_SESSION = $oldSession;
    }
});

test('actualiza solo su bloque de htaccess y permite retirar el bloqueo', function () {
    $directory = sys_get_temp_dir() . '/ep-ip-restriction-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $path = $directory . '/.htaccess';
    file_put_contents($path, "RewriteEngine On\nRewriteRule ^feed\\.xml$ feed.php [L]\n");

    try {
        writeAdminIpEntries($path, ['31.24.120.43', '192.0.64.0/18', '2a01:a940:406::/48']);
        $contents = (string) file_get_contents($path);
        assert_contains('RewriteRule ^feed\\.xml$ feed.php [L]', $contents);
        assert_contains('<Files "admin.php">', $contents);
        assert_contains('Deny from all', $contents);
        assert_contains('Allow from 192.0.64.0/18', $contents);
        assert_eq(['31.24.120.43', '192.0.64.0/18', '2a01:a940:406::/48'], readAdminIpEntries($path));

        writeAdminIpEntries($path, ['2001:db8::1']);
        $contents = (string) file_get_contents($path);
        assert_true(substr_count($contents, ADMIN_IP_BLOCK_START) === 1);
        assert_true(!str_contains($contents, '31.24.120.43'));
        assert_contains('Allow from 2001:db8::1', $contents);

        writeAdminIpEntries($path, []);
        $contents = (string) file_get_contents($path);
        assert_true(!str_contains($contents, ADMIN_IP_BLOCK_START));
        assert_contains('RewriteRule ^feed\\.xml$ feed.php [L]', $contents);
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
});

test('restaura htaccess desde una plantilla válida y elimina reglas personalizadas', function () {
    $directory = sys_get_temp_dir() . '/ep-htaccess-restore-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $path = $directory . '/.htaccess';
    $templatePath = $directory . '/.htaccess.default';
    $template = "RewriteEngine on\nRewriteRule ^podcast\\.sqlite$ - [F,L]\nOptions -Indexes\n";
    file_put_contents($path, "RewriteEngine on\nCustomRule enabled\n" . buildAdminIpBlock(['192.0.2.10']) . "\n");
    file_put_contents($templatePath, $template);

    try {
        restoreDefaultHtaccess($path, $templatePath);
        assert_eq($template, file_get_contents($path));
        assert_true(!str_contains((string) file_get_contents($path), ADMIN_IP_BLOCK_START));
    } finally {
        @unlink($path);
        @unlink($templatePath);
        @rmdir($directory);
    }
});
