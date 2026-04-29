<?php

declare(strict_types=1);

/**
 * Devuelve el token CSRF de la sesión actual, creándolo si no existe.
 * Requiere que la sesión esté activa antes de llamar a esta función.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/**
 * Devuelve true si el token CSRF enviado coincide con el de la sesión.
 */
function csrf_is_valid(?string $submitted = null): bool
{
    $submitted = $submitted ?? (string) ($_POST['csrf_token'] ?? '');
    $expected  = (string) ($_SESSION['csrf_token'] ?? '');

    return $submitted !== '' && $expected !== '' && hash_equals($expected, $submitted);
}

/**
 * Verifica el token CSRF enviado en POST contra el almacenado en sesión.
 * Termina la ejecución con HTTP 403 si la verificación falla.
 */
function csrf_verify(): void
{
    if (!csrf_is_valid()) {
        http_response_code(403);
        exit('Token de seguridad inválido. Vuelve atrás e inténtalo de nuevo.');
    }
}
