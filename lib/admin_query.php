<?php

declare(strict_types=1);

// Lógica de BD y autenticación para admin.php:
// - crea tabla management si no existe
// - gestiona logout, setup inicial y login normal
// - intercepta login si 2FA está habilitado (sesión pendiente → paso TOTP)

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/auth_security.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/access_control.php';

/**
 * Construye un mensaje uniforme de espera tras bloqueo temporal.
 */
function authThrottleErrorMessage(int $retryAfter): string
{
    $minutes = max(1, (int) ceil($retryAfter / 60));
    return __('Demasiados intentos fallidos. Espera %d minuto(s) antes de volver a intentarlo.', $minutes);
}

/**
 * Atiende el cierre de sesión del panel por POST protegido con CSRF.
 */
function handleAdminLogoutRequest(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (string) ($_POST['action'] ?? '') !== 'logout') {
        return;
    }

    csrf_verify();
    $_SESSION = [];
    session_destroy();
    clearTrustedDeviceCookie();
    header('Location: admin.php');
    exit;
}

/**
 * Procesa la sesión de administración (login, setup, logout) y retorna
 * los datos necesarios para renderizar la vista.
 *
 * Efectos secundarios: puede destruir sesión, iniciar sesión, redirigir y salir.
 *
 * @return array{adminCount:int, isSetupMode:bool, error:string, notice:string}
 */
function loadAdminData(string $dbPath): array
{
    $error = '';
    $notice = '';
    $adminCount = 0;

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mantiene una única tabla de gestión para credenciales admin.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS management (
              id INTEGER PRIMARY KEY,
              username TEXT NOT NULL UNIQUE,
              password TEXT NOT NULL,
              created_at TEXT DEFAULT (datetime('now')),
              updated_at TEXT DEFAULT (datetime('now'))
            )"
        );

        $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM management')->fetchColumn();

        // Un único formulario maneja:
        // - setup inicial (no existe usuario admin)
        // - login normal (ya existe al menos uno)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['totp_pending_user'])) {
            csrf_verify();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $throttleState = authReserveAttempt('login', $username);

            if ($throttleState['blocked']) {
                $error = authThrottleErrorMessage($throttleState['retry_after']);
                return compact('adminCount', 'error', 'notice') + ['isSetupMode' => ($adminCount === 0)];
            }

            if ($adminCount === 0) {
                // Modo setup: crea la primera cuenta administradora.
                $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

                if ($username === '' || $password === '' || $passwordConfirm === '') {
                    $error = __('Completa usuario, contraseña y confirmación.');
                } elseif ($password !== $passwordConfirm) {
                    $error = __('Las contraseñas no coinciden.');
                } elseif (strlen($password) < 8) {
                    $error = __('La contraseña debe tener al menos 8 caracteres.');
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO management (username, email, password, is_global) VALUES (:username, :email, :password, 1)');
                    $stmt->execute([
                        ':username' => $username,
                        ':email' => $username,
                        ':password' => $hash,
                    ]);

                    session_regenerate_id(true);
                    establishAdminSession([
                        'id' => (int) $pdo->lastInsertId(),
                        'username' => $username,
                        'is_global' => 1,
                        'podcast_ids' => [],
                    ]);
                    authClearThrottle('login', $username);
                    $notice = __('Usuario administrador creado correctamente.');
                    $adminCount = 1;
                }
            } else {
                // Modo normal: autentica un usuario admin existente.
                if ($username === '' || $password === '') {
                    $error = __('Introduce usuario y contraseña.');
                } else {
                    $stmt = $pdo->prepare('SELECT id, username, password, is_global, is_active, totp_enabled, totp_secret, totp_recovery_codes FROM management WHERE username = :username OR email = :email LIMIT 1');
                    $stmt->execute([':username' => $username, ':email' => strtolower($username)]);
                    $row = $stmt->fetch();

                    if (!$row || (int) ($row['is_active'] ?? 1) !== 1) {
                        $error = $throttleState['retry_after'] > 0
                            ? authThrottleErrorMessage($throttleState['retry_after'])
                            : __('Credenciales inválidas.');
                    } else {
                        $stored = (string) $row['password'];
                        // El fallback con hash_equals permite migrar contraseñas legacy en texto plano.
                        $valid = password_verify($password, $stored) || hash_equals($stored, $password);

                        if (!$valid) {
                            $retryAfter = $throttleState['retry_after'];
                            if ($retryAfter > 0) {
                                $error = authThrottleErrorMessage($retryAfter);
                            } else {
                                $error = __('Credenciales inválidas.');
                            }
                        } else {
                            $assigned = $pdo->prepare('SELECT podcast_id FROM management_podcasts WHERE management_id = :id ORDER BY podcast_id');
                            $assigned->execute([':id' => (int) $row['id']]);
                            $row['podcast_ids'] = array_map('intval', $assigned->fetchAll(PDO::FETCH_COLUMN));
                            authClearThrottle('login', $username);
                            // Si una contraseña legacy coincide por fallback, se rehashea de forma transparente.
                            if (!password_verify($password, $stored)) {
                                $rehashStmt = $pdo->prepare(
                                    'UPDATE management SET password = :password, updated_at = datetime(\'now\') WHERE id = :id'
                                );
                                $rehashStmt->execute([
                                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                                    ':id' => (int) $row['id'],
                                ]);
                            }

                            if ((int) ($row['totp_enabled'] ?? 0) === 1 && (string) ($row['totp_secret'] ?? '') !== '') {
                                // 2FA activo: comprobar si el dispositivo ya fue marcado como confiado.
                                if (isTrustedDevice((string) $row['username'], (string) $row['totp_secret'])) {
                                    session_regenerate_id(true);
                                    unset($_SESSION['totp_pending_user']);
                                    establishAdminSession($row);
                                    header('Location: admin.php');
                                    exit;
                                }
                                // Sesión pendiente hasta verificar el código TOTP.
                                session_regenerate_id(true);
                                unset($_SESSION['admin_user']);
                                $_SESSION['totp_pending_user'] = (string) $row['username'];
                                header('Location: admin.php');
                                exit;
                            }

                            session_regenerate_id(true);
                            unset($_SESSION['totp_pending_user']);
                            establishAdminSession($row);
                            header('Location: admin.php');
                            exit;
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en admin.php: ' . $e->getMessage() . "\n";
        exit;
    }

    $isSetupMode = ($adminCount === 0);

    return compact('adminCount', 'isSetupMode', 'error', 'notice');
}

/**
 * Procesa la verificación TOTP del paso intermedio de login.
 * Se llama cuando $_SESSION['totp_pending_user'] está presente.
 * Acepta un código TOTP de 6 dígitos o un código de recuperación.
 * En éxito: establece admin_user, limpia la sesión pendiente y redirige.
 * Devuelve el mensaje de error si la verificación falla.
 */
function verifyTotpLogin(string $dbPath): string
{
    $pendingUser = (string) ($_SESSION['totp_pending_user'] ?? '');
    if ($pendingUser === '') {
        return '';
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }

    csrf_verify();

    $throttleState = authReserveAttempt('totp', $pendingUser);
    if ($throttleState['blocked']) {
        return authThrottleErrorMessage($throttleState['retry_after']);
    }

    $code = trim((string) ($_POST['totp_code'] ?? ''));
    if ($code === '') {
        return __('Introduce el código de verificación.');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT id, username, is_global, is_active, totp_secret, totp_recovery_codes FROM management WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $pendingUser]);
        $row = $stmt->fetch();
        if ($row) {
            $assigned = $pdo->prepare('SELECT podcast_id FROM management_podcasts WHERE management_id = :id ORDER BY podcast_id');
            $assigned->execute([':id' => (int) $row['id']]);
            $row['podcast_ids'] = array_map('intval', $assigned->fetchAll(PDO::FETCH_COLUMN));
        }

        if (!$row || (int) ($row['is_active'] ?? 1) !== 1) {
            unset($_SESSION['totp_pending_user']);
            return __('Credenciales inválidas.');
        }
        if ((string) ($row['totp_secret'] ?? '') === '') {
            // Situación anómala: el usuario ya no tiene 2FA configurado.
            unset($_SESSION['totp_pending_user']);
            session_regenerate_id(true);
            establishAdminSession($row);
            header('Location: admin.php');
            exit;
        }

        $secret = (string) $row['totp_secret'];

        // Intento 1: código TOTP de 6 dígitos.
        if (totpVerify($secret, $code)) {
            authClearThrottle('totp', $pendingUser);
            unset($_SESSION['totp_pending_user']);
            session_regenerate_id(true);
            establishAdminSession($row);
            if (!empty($_POST['remember_device'])) {
                setTrustedDeviceCookie($pendingUser, $secret);
            }
            header('Location: admin.php');
            exit;
        }

        // Intento 2: código de recuperación (formato XXXX-XXXX, insensible a mayúsculas).
        $storedJson = (string) ($row['totp_recovery_codes'] ?? '[]');
        $updatedJson = totpVerifyRecoveryCode($code, $storedJson);
        if ($updatedJson !== null) {
            authClearThrottle('totp', $pendingUser);
            $upd = $pdo->prepare('UPDATE management SET totp_recovery_codes = :rc WHERE id = :id');
            $upd->execute([':rc' => $updatedJson, ':id' => (int) $row['id']]);
            unset($_SESSION['totp_pending_user']);
            session_regenerate_id(true);
            establishAdminSession($row);
            if (!empty($_POST['remember_device'])) {
                setTrustedDeviceCookie($pendingUser, $secret);
            }
            header('Location: admin.php');
            exit;
        }

        $retryAfter = $throttleState['retry_after'];
        if ($retryAfter > 0) {
            return authThrottleErrorMessage($retryAfter);
        }

        return __('Código incorrecto. Inténtalo de nuevo.');
    } catch (Throwable $e) {
        return __('Error interno al verificar el código.');
    }
}

/**
 * Comprueba si la cookie del dispositivo confiado es válida.
 * La cookie tiene el formato: hex_token|expires_unix|hmac
 * El HMAC se calcula sobre "hex_token|expires_unix|username" usando totp_secret como clave.
 * Si el usuario desactiva o resetea el 2FA, el secreto cambia y la cookie queda invalidada.
 */
function isTrustedDevice(string $username, string $totpSecret): bool
{
    $cookie = (string) ($_COOKIE['easypodcast_trusted'] ?? '');
    if ($cookie === '') {
        return false;
    }

    $parts = explode('|', $cookie, 3);
    if (count($parts) !== 3) {
        return false;
    }

    [$token, $expires, $hmac] = $parts;

    if ((int) $expires <= time()) {
        return false;
    }

    $expected = hash_hmac('sha256', "{$token}|{$expires}|{$username}", $totpSecret);

    return hash_equals($expected, $hmac);
}

/**
 * Establece la cookie de dispositivo confiado durante 7 días.
 */
function setTrustedDeviceCookie(string $username, string $totpSecret): void
{
    $token   = bin2hex(random_bytes(16));
    $expires = time() + 604800; // 7 días
    $hmac    = hash_hmac('sha256', "{$token}|{$expires}|{$username}", $totpSecret);
    $value   = "{$token}|{$expires}|{$hmac}";
    $secure  = isSecureHttpRequest();

    setcookie('easypodcast_trusted', $value, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Strict',
    ]);
}

/**
 * Borra la cookie de dispositivo confiado (al hacer logout).
 */
function clearTrustedDeviceCookie(): void
{
    setcookie('easypodcast_trusted', '', [
        'expires'  => 1,
        'path'     => '/',
        'httponly' => true,
        'secure'   => isSecureHttpRequest(),
        'samesite' => 'Strict',
    ]);
}
