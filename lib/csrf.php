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
 * Verifica el token CSRF enviado en POST contra el almacenado en sesión.
 * Termina la ejecución con HTTP 403 si la verificación falla.
 */
function csrf_verify(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    $expected  = (string) ($_SESSION['csrf_token'] ?? '');

    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('Token de seguridad inválido. Vuelve atrás e inténtalo de nuevo.');
    }
}
