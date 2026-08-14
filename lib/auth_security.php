<?php

declare(strict_types=1);

const AUTH_THROTTLE_WINDOW_SECONDS = 900;
const AUTH_THROTTLE_BLOCK_SECONDS = 900;
const AUTH_THROTTLE_MAX_ATTEMPTS = 5;

/**
 * Devuelve la IP cliente más fiable disponible.
 */
function authClientAddress(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : 'unknown';
}

/**
 * Devuelve el directorio donde se guardan los contadores de intentos.
 */
function authThrottleDirectory(): string
{
    $dir = dirname(__DIR__) . '/cache/security';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Genera la ruta del fichero de throttle.
 */
function authThrottlePath(string $kind, string $identity = ''): string
{
    $normalizedIdentity = strtolower(trim($identity));
    $key = $kind . '|' . authClientAddress() . '|' . $normalizedIdentity;
    return authThrottleDirectory() . '/' . hash('sha256', $key) . '.json';
}

/**
 * @return array{attempts:array<int,int>, blocked_until:int}
 */
function authLoadThrottleRecord(string $path): array
{
    $default = ['attempts' => [], 'blocked_until' => 0];
    if (!is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }

    $attempts = array_values(array_filter(
        array_map('intval', (array) ($decoded['attempts'] ?? [])),
        static fn(int $ts): bool => $ts > 0
    ));

    return [
        'attempts' => $attempts,
        'blocked_until' => max(0, (int) ($decoded['blocked_until'] ?? 0)),
    ];
}

/**
 * Persiste el estado de throttle.
 *
 * @param array{attempts:array<int,int>, blocked_until:int} $record
 */
function authSaveThrottleRecord(string $path, array $record): void
{
    @file_put_contents(
        $path,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * Ejecuta una modificación lectura-escritura bajo un único bloqueo exclusivo.
 *
 * @template T
 * @param callable(array{attempts:array<int,int>,blocked_until:int}):array{record:array{attempts:array<int,int>,blocked_until:int},result:T} $callback
 * @return T
 */
function authMutateThrottleRecord(string $path, callable $callback): mixed
{
    $handle = @fopen($path, 'c+');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        $mutation = $callback(['attempts' => [], 'blocked_until' => 0]);
        return $mutation['result'];
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $record = ['attempts' => [], 'blocked_until' => 0];
    if (is_array($decoded)) {
        $record['attempts'] = array_values(array_filter(
            array_map('intval', (array) ($decoded['attempts'] ?? [])),
            static fn(int $ts): bool => $ts > 0
        ));
        $record['blocked_until'] = max(0, (int) ($decoded['blocked_until'] ?? 0));
    }

    $mutation = $callback($record);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string) json_encode(
        $mutation['record'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $mutation['result'];
}

/**
 * Elimina intentos viejos fuera de ventana.
 *
 * @param array<int,int> $attempts
 * @return array<int,int>
 */
function authPruneAttempts(array $attempts, int $now): array
{
    $cutoff = $now - AUTH_THROTTLE_WINDOW_SECONDS;
    return array_values(array_filter(
        $attempts,
        static fn(int $ts): bool => $ts >= $cutoff
    ));
}

/**
 * Consulta si un flujo está bloqueado temporalmente.
 *
 * @return array{blocked:bool,retry_after:int}
 */
function authGetThrottleState(string $kind, string $identity = ''): array
{
    $now = time();
    $path = authThrottlePath($kind, $identity);
    if (!is_file($path)) {
        return ['blocked' => false, 'retry_after' => 0];
    }

    return authMutateThrottleRecord($path, static function (array $record) use ($now): array {
        $record['attempts'] = authPruneAttempts($record['attempts'], $now);
        if ($record['blocked_until'] <= $now) {
            $record['blocked_until'] = 0;
        }
        return [
            'record' => $record,
            'result' => [
                'blocked' => $record['blocked_until'] > $now,
                'retry_after' => max(0, $record['blocked_until'] - $now),
            ],
        ];
    });
}

/**
 * Reserva atómicamente un intento antes de verificar credenciales.
 * Así las peticiones concurrentes no pueden superar el límite por carreras.
 *
 * @return array{blocked:bool,retry_after:int}
 */
function authReserveAttempt(string $kind, string $identity = ''): array
{
    $path = authThrottlePath($kind, $identity);
    return authReserveAttemptAtPath($path);
}

/**
 * Variante sobre una ruta explícita para centralizar la operación atómica.
 *
 * @return array{blocked:bool,retry_after:int}
 */
function authReserveAttemptAtPath(string $path, ?int $now = null): array
{
    $now = $now ?? time();

    return authMutateThrottleRecord($path, static function (array $record) use ($now): array {
        $record['attempts'] = authPruneAttempts($record['attempts'], $now);
        if ($record['blocked_until'] > $now) {
            return [
                'record' => $record,
                'result' => [
                    'blocked' => true,
                    'retry_after' => $record['blocked_until'] - $now,
                ],
            ];
        }

        $record['blocked_until'] = 0;
        $record['attempts'][] = $now;
        if (count($record['attempts']) >= AUTH_THROTTLE_MAX_ATTEMPTS) {
            $record['blocked_until'] = $now + AUTH_THROTTLE_BLOCK_SECONDS;
        }

        return [
            'record' => $record,
            'result' => [
                'blocked' => false,
                'retry_after' => max(0, $record['blocked_until'] - $now),
            ],
        ];
    });
}

/**
 * Registra un intento fallido y devuelve los segundos de bloqueo resultantes.
 */
function authRegisterFailure(string $kind, string $identity = ''): int
{
    $state = authReserveAttempt($kind, $identity);
    return $state['retry_after'];
}

/**
 * Limpia el estado de throttle tras autenticación correcta.
 */
function authClearThrottle(string $kind, string $identity = ''): void
{
    $path = authThrottlePath($kind, $identity);
    if (!is_file($path)) {
        return;
    }

    authMutateThrottleRecord($path, static fn(array $record): array => [
        'record' => ['attempts' => [], 'blocked_until' => 0],
        'result' => null,
    ]);
}
