<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// TOTP (RFC 6238) — implementación pura PHP sin dependencias externas.
// ---------------------------------------------------------------------------

/**
 * Decodifica una cadena Base32 a bytes binarios (RFC 4648).
 */
function base32Decode(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32    = strtoupper(rtrim($base32, '='));
    $binary    = '';
    $buffer    = 0;
    $bitsLeft  = 0;

    for ($i = 0; $i < strlen($base32); $i++) {
        $pos = strpos($alphabet, $base32[$i]);
        if ($pos === false) {
            continue;
        }
        $buffer   = ($buffer << 5) | $pos;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $binary   .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $binary;
}

/**
 * Codifica bytes binarios a Base32 (RFC 4648).
 */
function base32Encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $result   = '';
    $buffer   = 0;
    $bitsLeft = 0;

    for ($i = 0; $i < strlen($bytes); $i++) {
        $buffer    = ($buffer << 8) | ord($bytes[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $result   .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
        }
    }

    if ($bitsLeft > 0) {
        $result .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
    }

    return $result;
}

/**
 * Genera un secreto TOTP aleatorio de 20 bytes codificado en Base32.
 */
function totpGenerateSecret(): string
{
    return base32Encode(random_bytes(20));
}

/**
 * Calcula un código TOTP de 6 dígitos para el secreto y el offset de ventana dados.
 * Offset 0 = ventana actual; -1 = anterior; +1 = siguiente.
 */
function totpCode(string $secret, int $offset = 0, int $timeStep = 30): string
{
    $key     = base32Decode($secret);
    $counter = (int) floor(time() / $timeStep) + $offset;
    // 8 bytes big-endian: 4 bytes altos = 0, 4 bytes bajos = contador.
    $timeBytes = pack('NN', 0, $counter);
    $hash      = hash_hmac('sha1', $timeBytes, $key, true);
    $off       = ord($hash[19]) & 0x0F;
    $code      = (
        ((ord($hash[$off])     & 0x7F) << 24) |
        ((ord($hash[$off + 1]) & 0xFF) << 16) |
        ((ord($hash[$off + 2]) & 0xFF) << 8)  |
         (ord($hash[$off + 3]) & 0xFF)
    ) % 1000000;

    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código TOTP con tolerancia de ±1 ventana (±30 s).
 * Devuelve true si el código es válido.
 */
function totpVerify(string $secret, string $code): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totpCode($secret, $i), $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Construye el URI otpauth:// para escaneo con apps TOTP como Google Authenticator.
 */
function totpQrUri(string $secret, string $account, string $issuer): string
{
    $label = rawurlencode($issuer . ':' . $account);
    return 'otpauth://totp/' . $label
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Genera N códigos de recuperación en formato XXXX-XXXX.
 * Devuelve ['plain' => [...], 'hashed' => [...]] donde 'hashed' usa password_hash.
 *
 * @return array{plain: string[], hashed: string[]}
 */
function totpGenerateRecoveryCodes(int $count = 8): array
{
    $plain  = [];
    $hashed = [];

    for ($i = 0; $i < $count; $i++) {
        $raw    = strtoupper(bin2hex(random_bytes(4)));
        $code   = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        $plain[]  = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }

    return ['plain' => $plain, 'hashed' => $hashed];
}

/**
 * Verifica un código de recuperación contra la lista de hashes almacenados en JSON.
 * Si es válido, elimina ese código de la lista (uso único) y devuelve el JSON actualizado.
 * Devuelve null si el código no coincide con ninguno.
 */
function totpVerifyRecoveryCode(string $code, string $storedJson): ?string
{
    $code   = strtoupper(trim(str_replace([' ', '-'], ['' , '-'], $code)));
    $hashes = json_decode($storedJson, true);

    if (!is_array($hashes)) {
        return null;
    }

    foreach ($hashes as $index => $hash) {
        if (password_verify($code, (string) $hash)) {
            array_splice($hashes, $index, 1);
            return json_encode(array_values($hashes));
        }
    }

    return null;
}
