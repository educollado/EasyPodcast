<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/download_handler.php';

// =============================================================================
// isRedirectTrackingAction
// =============================================================================

test('isRedirectTrackingAction: download sin AJAX redirige al audio', function () {
    assert_true(isRedirectTrackingAction('download', false));
});

test('isRedirectTrackingAction: feed sin AJAX redirige al audio', function () {
    assert_true(isRedirectTrackingAction('feed', false));
});

test('isRedirectTrackingAction: play no redirige al audio', function () {
    assert_true(!isRedirectTrackingAction('play', false));
});

test('isRedirectTrackingAction: ninguna acción AJAX redirige', function () {
    assert_true(!isRedirectTrackingAction('download', true));
    assert_true(!isRedirectTrackingAction('feed', true));
});

// =============================================================================
// shouldRegisterTrackingRequest
// =============================================================================

test('shouldRegisterTrackingRequest: GET cuenta en estadísticas', function () {
    assert_true(shouldRegisterTrackingRequest('GET'));
});

test('shouldRegisterTrackingRequest: HEAD no cuenta en estadísticas', function () {
    assert_true(!shouldRegisterTrackingRequest('HEAD'));
});

test('shouldRegisterTrackingRequest: el método se normaliza en mayúsculas', function () {
    assert_true(!shouldRegisterTrackingRequest('head'));
});
