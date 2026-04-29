<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/csp.php';

test('cspNonce: es estable dentro de la misma request', function () {
    $first = cspNonce();
    $second = cspNonce();

    assert_eq($first, $second);
    assert_matches('/^[A-Za-z0-9_-]{20,}$/', $first);
});

test('cspNonceAttr: expone el nonce como atributo HTML', function () {
    $nonce = cspNonce();
    assert_eq(' nonce="' . $nonce . '"', cspNonceAttr());
});

test('buildContentSecurityPolicyValue: exige nonce para script-src', function () {
    $policy = buildContentSecurityPolicyValue('abc123');

    assert_contains("script-src 'self' 'nonce-abc123'", $policy);
    assert_contains("script-src-attr 'none'", $policy);
    assert_true(!str_contains($policy, "script-src 'self' 'unsafe-inline'"));
});

test('buildContentSecurityPolicyValue: style-src es estricto por defecto', function () {
    $policy = buildContentSecurityPolicyValue('abc123');

    assert_contains("style-src 'self' https://fonts.googleapis.com", $policy);
    assert_true(!str_contains($policy, "style-src 'self' 'unsafe-inline'"));
});

test('cspAllowsInlineStylesForScript: solo relaja las pantallas con dependencias inline', function () {
    assert_true(cspAllowsInlineStylesForScript('add_episode.php'));
    assert_true(cspAllowsInlineStylesForScript('add_page.php'));
    assert_true(cspAllowsInlineStylesForScript('twofa_management.php'));
    assert_true(!cspAllowsInlineStylesForScript('index.php'));
});

test('buildContentSecurityPolicyValue: permite unsafe-inline solo cuando se solicita', function () {
    $policy = buildContentSecurityPolicyValue('abc123', true);

    assert_contains("style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", $policy);
});
