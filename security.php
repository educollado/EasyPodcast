<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/admin_ip_restriction.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';
if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
requireGlobalAdminAccess();
header('X-Robots-Tag: noindex, nofollow, noarchive');

$securityError = '';
$securityNotice = '';
$securityConfirmationPending = false;
$securityHtaccessPath = __DIR__ . '/.htaccess';

try {
    $securityEntries = readAdminIpEntries($securityHtaccessPath);
} catch (Throwable $e) {
    $securityEntries = [];
    $securityError = $e->getMessage();
}

$securityInput = implode("\n", $securityEntries);
$securityEnabled = $securityEntries !== [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $securityAction = (string) ($_POST['action'] ?? '');
    $securityInput = trim((string) ($_POST['admin_allowed_ips'] ?? ''));
    $parsedEntries = parseAdminIpEntries($securityInput);

    if ($securityAction !== 'disable' && $parsedEntries['invalid'] !== []) {
        $securityError = __('Estas direcciones o rangos no son válidos: %s', implode(', ', $parsedEntries['invalid']));
    } elseif ($securityAction !== 'disable' && $parsedEntries['entries'] === []) {
        $securityError = __('Añade al menos una dirección IP o rango antes de habilitar el bloqueo.');
    } else {
        try {
            if ($securityAction === 'request_enable' && !$securityEnabled) {
                prepareAdminIpConfirmation($parsedEntries['entries']);
                $securityConfirmationPending = true;
                $securityInput = implode("\n", $parsedEntries['entries']);
            } elseif ($securityAction === 'confirm_enable' && !$securityEnabled) {
                if (!consumeAdminIpConfirmation($parsedEntries['entries'])) {
                    throw new RuntimeException(__('La confirmación ha caducado o la lista ha cambiado. Vuelve a iniciar la activación.'));
                }
                writeAdminIpEntries($securityHtaccessPath, $parsedEntries['entries']);
                $securityEntries = $parsedEntries['entries'];
                $securityInput = implode("\n", $securityEntries);
                $securityEnabled = true;
                $securityNotice = __('El bloqueo de acceso a admin.php por IP se ha habilitado correctamente.');
            } elseif ($securityAction === 'save' && $securityEnabled) {
                writeAdminIpEntries($securityHtaccessPath, $parsedEntries['entries']);
                $securityEntries = $parsedEntries['entries'];
                $securityInput = implode("\n", $securityEntries);
                $securityNotice = __('El bloqueo de acceso a admin.php por IP se ha actualizado correctamente.');
            } elseif ($securityAction === 'disable' && $securityEnabled) {
                writeAdminIpEntries($securityHtaccessPath, []);
                unset($_SESSION['admin_ip_confirmation']);
                $securityEntries = [];
                $securityInput = '';
                $securityEnabled = false;
                $securityNotice = __('Se ha desactivado el bloqueo de acceso a admin.php por IP.');
            } else {
                throw new RuntimeException(__('La acción de seguridad solicitada no es válida.'));
            }
        } catch (Throwable $e) {
            $securityError = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Seguridad') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
<?php $currentAdminPage = 'security'; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-wrap"><main class="card">
  <h1><?= __('Seguridad') ?></h1>
  <p><?= __('Configura restricciones adicionales para proteger el acceso administrativo.') ?></p>
  <?php if ($securityError !== ''): ?><div class="error" role="alert"><?= esc($securityError) ?></div><?php endif; ?>
  <?php if ($securityNotice !== ''): ?><div class="notice" role="status"><?= esc($securityNotice) ?></div><?php endif; ?>

  <h2><?= __('Bloqueo de IP a admin.php') ?></h2>
  <p><strong><?= __('Estado') ?>:</strong> <?= $securityEnabled ? __('Habilitado') : __('Deshabilitado') ?></p>
  <p><?= __('Solo las direcciones indicadas podrán abrir la página de acceso. Se admiten direcciones y rangos CIDR en IPv4 e IPv6.') ?></p>
  <form method="post" class="form-stack form-narrow">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <label>
      <?= __('Direcciones IP o rangos permitidos') ?>
      <textarea name="admin_allowed_ips" spellcheck="false" placeholder="192.0.2.10&#10;198.51.100.0/24&#10;2001:db8::10&#10;2001:db8:1234::/48"><?= esc($securityInput) ?></textarea>
      <span class="help"><?= __('Añade una dirección o rango por línea.') ?></span>
    </label>
    <?php if ($securityConfirmationPending): ?>
      <div class="error" role="alert"><?= __('Has solicitado habilitar el bloqueo por IP. Esta medida puede impedirte volver a acceder a admin.php. La habilitas voluntariamente y bajo tu propia responsabilidad. Comprueba que has incluido tu IP actual y pulsa «Estoy seguro» para confirmar.') ?></div>
      <div class="actions">
        <button class="btn btn-danger" type="submit" name="action" value="confirm_enable"><?= __('Estoy seguro') ?></button>
        <a class="btn back" href="security.php"><?= __('Cancelar') ?></a>
      </div>
    <?php elseif ($securityEnabled): ?>
      <div class="actions">
        <button class="btn" type="submit" name="action" value="save"><?= __('Guardar cambios') ?></button>
        <button class="btn btn-danger" type="submit" name="action" value="disable"><?= __('Deshabilitar bloqueo') ?></button>
      </div>
    <?php else: ?>
      <div class="actions"><button class="btn" type="submit" name="action" value="request_enable"><?= __('Habilitar bloqueo') ?></button></div>
    <?php endif; ?>
  </form>
</main></div>
</body>
</html>
