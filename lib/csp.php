<?php

declare(strict_types=1);

/**
 * Devuelve el nonce CSP estable durante toda la request.
 */
function cspNonce(): string
{
    if (!isset($GLOBALS['_csp_nonce'])) {
        $GLOBALS['_csp_nonce'] = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    return (string) $GLOBALS['_csp_nonce'];
}

/**
 * Devuelve el atributo nonce para scripts inline permitidos.
 */
function cspNonceAttr(): string
{
    return ' nonce="' . cspNonce() . '"';
}

/**
 * Devuelve true si la ruta actual necesita estilos inline por compatibilidad.
 */
function cspAllowsInlineStylesForScript(?string $scriptName = null): bool
{
    $scriptName = $scriptName ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    // Jodit y el generador QR siguen necesitando estilos inline generados en runtime.
    return in_array($scriptName, ['add_episode.php', 'add_page.php', 'twofa_management.php'], true);
}

/**
 * Construye la cabecera CSP activa.
 */
function buildContentSecurityPolicyValue(string $nonce, bool $allowInlineStyles = false): string
{
    return implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'self'",
        "form-action 'self'",
        "img-src 'self' https: data:",
        "media-src 'self' https:",
        "font-src 'self' https://fonts.gstatic.com data:",
        "style-src 'self'" . ($allowInlineStyles ? " 'unsafe-inline'" : '') . " https://fonts.googleapis.com",
        "script-src 'self' 'nonce-" . $nonce . "'",
        "script-src-attr 'none'",
        "connect-src 'self'",
        "frame-src https:",
        "upgrade-insecure-requests",
    ]);
}

/**
 * Envía la cabecera CSP si la request está en contexto web.
 */
function sendContentSecurityPolicyHeaders(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('Content-Security-Policy: ' . buildContentSecurityPolicyValue(
        cspNonce(),
        cspAllowsInlineStylesForScript()
    ));
}
