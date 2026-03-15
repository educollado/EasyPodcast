<?php

declare(strict_types=1);

require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/i18n.php';

/**
 * Carga y procesa el estado de la gestión de 2FA del usuario en sesión.
 *
 * Estados posibles en $state:
 *   'disabled'      — 2FA no configurado
 *   'setup_pending' — Secreto generado en sesión, pendiente de confirmación
 *   'enabled'       — 2FA activo
 *
 * @return array{state:string, newCodes:string[], qrUri:string, pendingSecret:string,
 *               recoveryCount:int, error:string, notice:string}
 */
function loadTwofaData(string $dbPath): array
{
    $error        = '';
    $notice       = '';
    $state        = 'disabled';
    $newCodes     = [];
    $qrUri        = '';
    $pendingSecret = '';
    $recoveryCount = 0;

    $username = (string) ($_SESSION['admin_user'] ?? '');

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT id, totp_enabled, totp_secret, totp_recovery_codes FROM management WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = __('Usuario no encontrado.');
            return compact('state', 'newCodes', 'qrUri', 'pendingSecret', 'recoveryCount', 'error', 'notice');
        }

        $userId        = (int) $row['id'];
        $totpEnabled   = (int) ($row['totp_enabled'] ?? 0) === 1;
        $totpSecret    = (string) ($row['totp_secret'] ?? '');
        $recoveryCodes = (string) ($row['totp_recovery_codes'] ?? '[]');

        // Issuer: dominio del podcast (podcast.link) o host actual como fallback.
        $podcastRow = $pdo->query('SELECT link FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
        $podcastLink = (string) ($podcastRow['link'] ?? '');
        $issuer = ($podcastLink !== '')
            ? (parse_url($podcastLink, PHP_URL_HOST) ?: $podcastLink)
            : (string) ($_SERVER['HTTP_HOST'] ?? 'EasyPodcast');

        // Determinar estado base.
        if ($totpEnabled && $totpSecret !== '') {
            $state = 'enabled';
            $storedHashes = json_decode($recoveryCodes, true);
            $recoveryCount = is_array($storedHashes) ? count($storedHashes) : 0;
        } elseif (isset($_SESSION['totp_setup_secret'])) {
            $state         = 'setup_pending';
            $pendingSecret = (string) $_SESSION['totp_setup_secret'];
            $qrUri         = totpQrUri($pendingSecret, $username, $issuer);
        }

        // Procesar acciones POST.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = (string) ($_POST['twofa_action'] ?? '');

            if ($action === 'start_setup') {
                // Genera un nuevo secreto y lo almacena en sesión hasta confirmación.
                $secret = totpGenerateSecret();
                $_SESSION['totp_setup_secret'] = $secret;
                $state         = 'setup_pending';
                $pendingSecret = $secret;
                $qrUri         = totpQrUri($pendingSecret, $username, $issuer);

            } elseif ($action === 'confirm_setup') {
                // Verifica el primer código TOTP para confirmar que el usuario ha configurado bien su app.
                $pendingSecret = (string) ($_SESSION['totp_setup_secret'] ?? '');
                if ($pendingSecret === '') {
                    $error = __('No hay configuración de 2FA en curso. Vuelve a empezar.');
                    $state = 'disabled';
                } else {
                    $code = trim((string) ($_POST['totp_code'] ?? ''));
                    if (!totpVerify($pendingSecret, $code)) {
                        $error = __('Código incorrecto. Asegúrate de que tu app está sincronizada y vuelve a intentarlo.');
                        $state         = 'setup_pending';
                        $qrUri         = totpQrUri($pendingSecret, $username, $issuer);
                    } else {
                        // Código correcto: activar 2FA y generar códigos de recuperación.
                        $codes = totpGenerateRecoveryCodes(8);
                        $upd = $pdo->prepare(
                            'UPDATE management SET totp_secret = :s, totp_enabled = 1, totp_recovery_codes = :rc WHERE id = :id'
                        );
                        $upd->execute([
                            ':s'  => $pendingSecret,
                            ':rc' => json_encode($codes['hashed']),
                            ':id' => $userId,
                        ]);
                        unset($_SESSION['totp_setup_secret']);
                        $state         = 'enabled';
                        $newCodes      = $codes['plain'];
                        $recoveryCount = count($newCodes);
                        $notice        = __('2FA activado correctamente. Guarda los códigos de recuperación en un lugar seguro.');
                    }
                }

            } elseif ($action === 'disable') {
                if (!$totpEnabled || $totpSecret === '') {
                    $error = __('2FA ya está desactivado.');
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE management SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL WHERE id = :id'
                    );
                    $upd->execute([':id' => $userId]);
                    $state  = 'disabled';
                    $notice = __('2FA desactivado.');
                }

            } elseif ($action === 'regenerate_codes') {
                if (!$totpEnabled || $totpSecret === '') {
                    $error = __('2FA no está activo.');
                } else {
                    $codes = totpGenerateRecoveryCodes(8);
                    $upd = $pdo->prepare('UPDATE management SET totp_recovery_codes = :rc WHERE id = :id');
                    $upd->execute([':rc' => json_encode($codes['hashed']), ':id' => $userId]);
                    $state         = 'enabled';
                    $newCodes      = $codes['plain'];
                    $recoveryCount = count($newCodes);
                    $notice        = __('Nuevos códigos de recuperación generados. Los anteriores ya no son válidos.');
                }
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en twofa_management.php: ' . $e->getMessage() . "\n";
        exit;
    }

    return compact('state', 'newCodes', 'qrUri', 'pendingSecret', 'recoveryCount', 'error', 'notice');
}
