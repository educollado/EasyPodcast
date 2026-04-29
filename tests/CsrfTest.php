<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/csrf.php';

test('csrf_is_valid: devuelve true cuando el token coincide', function () {
    $_SESSION = ['csrf_token' => 'abc123'];
    assert_true(csrf_is_valid('abc123'));
});

test('csrf_is_valid: devuelve false cuando falta el token enviado', function () {
    $_SESSION = ['csrf_token' => 'abc123'];
    assert_true(!csrf_is_valid(''));
});

test('csrf_is_valid: devuelve false cuando no coincide con la sesión', function () {
    $_SESSION = ['csrf_token' => 'abc123'];
    assert_true(!csrf_is_valid('xyz789'));
});
