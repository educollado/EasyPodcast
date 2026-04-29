<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/twofa_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadTwofaData($dbPath);
extract($data); // state, newCodes, qrUri, pendingSecret, recoveryCount, error, notice
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Autenticación en dos pasos (2FA)') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'twofa'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Autenticación en dos pasos (2FA)') ?></h1>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <?php if ($state === 'disabled'): ?>
        <!-- ===================== 2FA DESACTIVADO ===================== -->
        <div class="twofa-status">
          <?= __('Estado') ?>: <span class="twofa-badge off"><?= __('Desactivado') ?></span>
        </div>
        <p><?= __('Activa la autenticación en dos pasos para proteger tu cuenta con Google Authenticator u otra app compatible con TOTP.') ?></p>

        <form method="post" action="twofa_management.php">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="twofa_action" value="start_setup">
          <div class="actions">
            <button class="btn" type="submit"><?= __('Activar 2FA') ?></button>
          </div>
        </form>

      <?php elseif ($state === 'setup_pending'): ?>
        <!-- ===================== CONFIGURACIÓN EN CURSO ===================== -->
        <div class="twofa-status">
          <?= __('Estado') ?>: <span class="twofa-badge off"><?= __('Configurando…') ?></span>
        </div>

        <p><strong>Paso 1</strong> — Escanea este código QR con tu app de autenticación:</p>
        <div id="qr-container" data-qr-uri="<?= esc($qrUri) ?>"></div>

        <p><?= __('¿No puedes escanear? Introduce esta clave manualmente en tu app:') ?></p>
        <div class="secret-text"><?= esc($pendingSecret) ?></div>

        <p class="section-gap-md"><strong>Paso 2</strong> — Introduce el código de 6 dígitos que muestra la app para confirmar:</p>

        <form method="post" action="twofa_management.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="twofa_action" value="confirm_setup">
          <label>
            <?= __('Código de verificación') ?>
            <input class="totp-input" type="text" name="totp_code" inputmode="numeric"
                   maxlength="6" required autofocus placeholder="000000" autocomplete="one-time-code">
          </label>
          <div class="actions section-gap-sm">
            <a class="btn back" href="twofa_management.php"><?= __('Cancelar') ?></a>
            <button class="btn" type="submit"><?= __('Confirmar y activar') ?></button>
          </div>
        </form>

        <script src="/assets/js/qrcode.min.js"></script>

      <?php elseif ($state === 'enabled'): ?>
        <!-- ===================== 2FA ACTIVO ===================== -->
        <div class="twofa-status">
          <?= __('Estado') ?>: <span class="twofa-badge on"><?= __('Activo') ?></span>
        </div>

        <?php if (!empty($newCodes)): ?>
          <!-- Códigos nuevos: se muestran una única vez -->
          <div class="notice notice-warning">
            <strong><?= __('Guarda estos códigos de recuperación ahora.') ?></strong>
            <?= __('No volverán a mostrarse. Úsalos si pierdes acceso a tu app de autenticación.') ?>
          </div>
          <div class="recovery-grid">
            <?php foreach ($newCodes as $code): ?>
              <div class="recovery-code"><?= esc($code) ?></div>
            <?php endforeach; ?>
          </div>
          <hr class="section-sep">
        <?php endif; ?>

        <p><?= __('Códigos de recuperación disponibles: <strong>%d</strong> de 8', $recoveryCount) ?></p>

        <!-- Regenerar códigos -->
        <details class="section-gap-md">
          <summary class="details-summary-link"><?= __('Regenerar códigos de recuperación') ?></summary>
          <div class="section-gap-sm">
            <p><?= __('Los códigos actuales quedarán anulados.') ?></p>
            <form method="post" action="twofa_management.php">
              <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
              <input type="hidden" name="twofa_action" value="regenerate_codes">
              <div class="actions">
                <button class="btn" type="submit"><?= __('Regenerar códigos') ?></button>
              </div>
            </form>
          </div>
        </details>

        <!-- Desactivar 2FA -->
        <div class="danger-zone">
          <h3><?= __('Desactivar 2FA') ?></h3>
          <p><?= __('Se eliminará el secreto y los códigos de recuperación.') ?></p>
          <form method="post" action="twofa_management.php">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="twofa_action" value="disable">
            <div class="actions">
              <button class="btn btn-danger" type="submit"><?= __('Desactivar 2FA') ?></button>
            </div>
          </form>
        </div>
  <?php endif; ?>

    </main>
  </div>
  <script src="/assets/js/twofa_management.js"></script>
</body>
</html>
