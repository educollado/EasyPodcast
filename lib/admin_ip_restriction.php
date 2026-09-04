<?php

declare(strict_types=1);

const ADMIN_IP_BLOCK_START = '# BEGIN EasyPodcast: bloqueo por IP de admin.php';
const ADMIN_IP_BLOCK_END = '# END EasyPodcast: bloqueo por IP de admin.php';
const ADMIN_IP_CONFIRMATION_TTL = 300;

/**
 * Valida y normaliza una lista de direcciones IPv4/IPv6 o rangos CIDR.
 *
 * @return array{entries:list<string>,invalid:list<string>}
 */
function parseAdminIpEntries(string $input): array
{
    $entries = [];
    $invalid = [];
    $values = preg_split('/[\s,;]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    foreach ($values as $value) {
        $parts = explode('/', $value);
        if (count($parts) > 2 || $parts[0] === '') {
            $invalid[] = $value;
            continue;
        }

        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            $invalid[] = $value;
            continue;
        }

        $normalized = inet_ntop($packed);
        if ($normalized === false) {
            $invalid[] = $value;
            continue;
        }

        if (isset($parts[1])) {
            $maximumPrefix = strlen($packed) === 4 ? 32 : 128;
            if ($parts[1] === '' || !ctype_digit($parts[1]) || (int) $parts[1] > $maximumPrefix) {
                $invalid[] = $value;
                continue;
            }
            $normalized .= '/' . (int) $parts[1];
        }

        $entries[$normalized] = true;
    }

    return ['entries' => array_keys($entries), 'invalid' => $invalid];
}

/** @param list<string> $entries */
function buildAdminIpBlock(array $entries): string
{
    if ($entries === []) {
        return '';
    }

    $lines = [
        ADMIN_IP_BLOCK_START,
        '# Bloqueo al admin.php',
        '<Files "admin.php">',
        '    Order Deny,Allow',
        '    Deny from all',
    ];
    foreach ($entries as $entry) {
        $lines[] = '    Allow from ' . $entry;
    }
    $lines[] = '</Files>';
    $lines[] = ADMIN_IP_BLOCK_END;

    return implode("\n", $lines);
}

/** @param list<string> $entries */
function prepareAdminIpConfirmation(array $entries, ?int $now = null): void
{
    $_SESSION['admin_ip_confirmation'] = [
        'fingerprint' => hash('sha256', implode("\n", $entries)),
        'expires_at' => ($now ?? time()) + ADMIN_IP_CONFIRMATION_TTL,
    ];
}

/** @param list<string> $entries */
function consumeAdminIpConfirmation(array $entries, ?int $now = null): bool
{
    $confirmation = $_SESSION['admin_ip_confirmation'] ?? null;
    unset($_SESSION['admin_ip_confirmation']);
    if (!is_array($confirmation)
        || !isset($confirmation['fingerprint'], $confirmation['expires_at'])
        || (int) $confirmation['expires_at'] < ($now ?? time())) {
        return false;
    }

    $expected = hash('sha256', implode("\n", $entries));
    return hash_equals((string) $confirmation['fingerprint'], $expected);
}

/** @return list<string> */
function readAdminIpEntries(string $htaccessPath): array
{
    $contents = @file_get_contents($htaccessPath);
    if ($contents === false) {
        throw new RuntimeException('No se pudo leer el archivo .htaccess.');
    }

    $block = findAdminIpBlock($contents);
    if ($block === null) {
        return [];
    }

    preg_match_all('/^\s*Allow\s+from\s+(\S+)\s*$/mi', $block, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

/** @param list<string> $entries */
function writeAdminIpEntries(string $htaccessPath, array $entries): void
{
    if (is_link($htaccessPath)) {
        throw new RuntimeException('No se puede modificar un .htaccess que sea un enlace simbólico.');
    }

    $contents = @file_get_contents($htaccessPath);
    if ($contents === false) {
        throw new RuntimeException('No se pudo leer el archivo .htaccess.');
    }

    $existingBlock = findAdminIpBlock($contents);
    if ($existingBlock !== null) {
        $updated = str_replace($existingBlock, '', $contents);
        $updated = rtrim($updated) . "\n";
    } else {
        $updated = rtrim($contents) . "\n";
    }

    $newBlock = buildAdminIpBlock($entries);
    if ($newBlock !== '') {
        $updated .= "\n" . $newBlock . "\n";
    }

    if ($updated === $contents) {
        return;
    }

    $directory = dirname($htaccessPath);
    $temporaryPath = tempnam($directory, '.htaccess-easypodcast-');
    if ($temporaryPath === false) {
        throw new RuntimeException('No se pudo preparar la actualización de .htaccess.');
    }

    try {
        if (file_put_contents($temporaryPath, $updated, LOCK_EX) !== strlen($updated)) {
            throw new RuntimeException('No se pudo escribir la actualización de .htaccess.');
        }
        $permissions = @fileperms($htaccessPath);
        if ($permissions !== false) {
            @chmod($temporaryPath, $permissions & 0777);
        }
        if (!@rename($temporaryPath, $htaccessPath)) {
            throw new RuntimeException('No se pudo reemplazar el archivo .htaccess.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
}

function findAdminIpBlock(string $contents): ?string
{
    $start = strpos($contents, ADMIN_IP_BLOCK_START);
    $end = strpos($contents, ADMIN_IP_BLOCK_END);
    if (($start === false) !== ($end === false) || ($start !== false && $end < $start)) {
        throw new RuntimeException('El bloque de seguridad de .htaccess está incompleto.');
    }
    if ($start === false) {
        return null;
    }

    $end += strlen(ADMIN_IP_BLOCK_END);
    if (substr($contents, $end, 2) === "\r\n") {
        $end += 2;
    } elseif (substr($contents, $end, 1) === "\n") {
        $end++;
    }

    return substr($contents, $start, $end - $start);
}
