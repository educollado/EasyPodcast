<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_verify(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    $expected  = (string) ($_SESSION['csrf_token'] ?? '');

    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('Token de seguridad inválido. Vuelve atrás e inténtalo de nuevo.');
    }
}
