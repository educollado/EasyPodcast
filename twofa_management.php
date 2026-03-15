<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/twofa_handler.php';

session_start();
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
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Autenticación en dos pasos (2FA)') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <style>
    .twofa-status { display:flex; align-items:center; gap:.6rem; margin-bottom:1.2rem; }
    .twofa-badge  { display:inline-block; padding:.2rem .7rem; border-radius:999px; font-size:.8rem; font-weight:600; }
    .twofa-badge.on  { background:#d1fae5; color:#065f46; }
    .twofa-badge.off { background:#fee2e2; color:#7f1d1d; }
    .recovery-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.5rem; margin:1rem 0; }
    .recovery-code { font-family:monospace; background:var(--bg); border:1px solid var(--border);
                     padding:.45rem .7rem; border-radius:6px; text-align:center; font-size:.95rem; letter-spacing:.05em; }
    #qr-container  { margin:1rem 0; display:inline-block; padding:.6rem; background:#fff; border:1px solid var(--border); border-radius:8px; }
    .totp-input    { letter-spacing:.2em; font-size:1.2rem; max-width:160px; }
    .secret-text   { font-family:monospace; font-size:.85rem; word-break:break-all; background:var(--bg);
                     border:1px solid var(--border); padding:.5rem .75rem; border-radius:6px; margin:.5rem 0; }
    .section-sep   { border:none; border-top:1px solid var(--border); margin:1.5rem 0; }
    .danger-zone   { background:#fff5f5; border:1px solid #fecaca; border-radius:8px; padding:1rem 1.2rem; margin-top:1.5rem; }
    .danger-zone h3 { margin:0 0 .5rem; color:var(--danger); font-size:1rem; }
  </style>
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
        <div id="qr-container"></div>

        <p><?= __('¿No puedes escanear? Introduce esta clave manualmente en tu app:') ?></p>
        <div class="secret-text"><?= esc($pendingSecret) ?></div>

        <p style="margin-top:1rem;"><strong>Paso 2</strong> — Introduce el código de 6 dígitos que muestra la app para confirmar:</p>

        <form method="post" action="twofa_management.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="twofa_action" value="confirm_setup">
          <label>
            <?= __('Código de verificación') ?>
            <input class="totp-input" type="text" name="totp_code" inputmode="numeric"
                   maxlength="6" required autofocus placeholder="000000" autocomplete="one-time-code">
          </label>
          <div class="actions" style="margin-top:.8rem;">
            <a class="btn back" href="twofa_management.php"><?= __('Cancelar') ?></a>
            <button class="btn" type="submit"><?= __('Confirmar y activar') ?></button>
          </div>
        </form>

        <script src="/assets/js/qrcode.min.js"></script>
        <script>
        new QRCode(document.getElementById('qr-container'), {
          text: <?= json_encode($qrUri) ?>,
          width: 200, height: 200,
          colorDark: '#000', colorLight: '#fff',
          correctLevel: QRCode.CorrectLevel.M
        });
        </script>

      <?php elseif ($state === 'enabled'): ?>
        <!-- ===================== 2FA ACTIVO ===================== -->
        <div class="twofa-status">
          <?= __('Estado') ?>: <span class="twofa-badge on"><?= __('Activo') ?></span>
        </div>

        <?php if (!empty($newCodes)): ?>
          <!-- Códigos nuevos: se muestran una única vez -->
          <div class="notice" style="background:#fffbeb; border-color:#f59e0b;">
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
        <details style="margin-top:1rem;">
          <summary style="cursor:pointer; color:var(--accent); font-weight:600;"><?= __('Regenerar códigos de recuperación') ?></summary>
          <div style="margin-top:.8rem;">
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
              <button class="btn" type="submit" style="background:var(--danger);"><?= __('Desactivar 2FA') ?></button>
            </div>
          </form>
        </div>
      <?php endif; ?>

    </main>
  </div>
</body>
</html>
