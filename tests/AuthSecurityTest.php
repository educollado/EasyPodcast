<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth_security.php';

test('authReserveAttemptAtPath contabiliza y bloquea intentos de forma persistente', function () {
    $path = tempnam(sys_get_temp_dir(), 'ep_auth_security_');
    assert_true(is_string($path));
    $now = 1700000000;

    try {
        for ($i = 1; $i <= AUTH_THROTTLE_MAX_ATTEMPTS; $i++) {
            $state = authReserveAttemptAtPath($path, $now);
            assert_true(!$state['blocked']);
        }

        $blocked = authReserveAttemptAtPath($path, $now);
        assert_true($blocked['blocked']);
        assert_eq(AUTH_THROTTLE_BLOCK_SECONDS, $blocked['retry_after']);

        $record = authLoadThrottleRecord($path);
        assert_eq(AUTH_THROTTLE_MAX_ATTEMPTS, count($record['attempts']));
    } finally {
        @unlink($path);
    }
});

