<?php

declare(strict_types=1);

// Punto de entrada de administración:
// - primera ejecución: crear usuario admin inicial
// - siguientes ejecuciones: login/logout y acceso a gestión

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/admin_query.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/update_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';
handleAdminLogoutRequest();

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$isLoggedIn    = isset($_SESSION['admin_user']);
$isTotpPending = !$isLoggedIn && isset($_SESSION['totp_pending_user']);
$adminMultipodcastEnabled = (bool) ($GLOBALS['_multipodcast_enabled'] ?? false);

// Cambio de tema visual desde el panel.
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_theme') {
    csrf_verify();
    $theme = trim((string) ($_POST['app_theme'] ?? 'easypodcast'));
    if (isset(ADMIN_THEMES[$theme])) {
        $publicThemeModeAuto = isset($_POST['public_theme_mode_auto']) ? 1 : 0;
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $podcastId = activePodcastId($pdo);
        $stmt = $pdo->prepare('UPDATE podcast SET admin_theme = :theme, public_theme_mode_auto = :public_theme_mode_auto WHERE id = :podcast_id');
        $stmt->execute([
            ':theme' => $theme,
            ':public_theme_mode_auto' => $publicThemeModeAuto,
            ':podcast_id' => $podcastId,
        ]);
        clearWebCache();
    }
    header('Location: admin.php');
    exit;
}

// Cambio de idioma rápido desde el panel.
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_language') {
    csrf_verify();
    $lang = trim((string) ($_POST['app_language'] ?? 'es_ES'));
    if (file_exists(__DIR__ . '/locale/' . $lang . '.po')) {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $podcastId = activePodcastId($pdo);
        $stmt = $pdo->prepare('UPDATE podcast SET app_language = :lang WHERE id = :podcast_id');
        $stmt->execute([':lang' => $lang, ':podcast_id' => $podcastId]);
        clearWebCache();
    }
    header('Location: admin.php');
    exit;
}

// Paso intermedio: verificación TOTP tras login correcto con 2FA activo.
$totpError = '';
if ($isTotpPending) {
    $totpError = verifyTotpLogin($dbPath);
}

$data = loadAdminData($dbPath);
extract($data); // adminCount, isSetupMode, error, notice

if (isset($_SESSION['admin_user']) && !isset($_GET['manage'])) {
    try {
        $contextPdo = openPodcastDatabase($dbPath);
        $multipodcastIsEnabled = loadAppSettings($contextPdo)['multipodcast_enabled'] === 1;
        $contextPdo = null;
        if ($multipodcastIsEnabled) {
            header('Location: multipodcast.php');
            exit;
        }
    } catch (Throwable $e) {
        // El panel normal continúa disponible si aún no existe la configuración.
    }
}

// Idioma activo para mostrar el selector.
$currentAppLanguage = 'es_ES';
// Tema activo para mostrar el selector.
$currentAdminTheme  = 'easypodcast';
$currentPublicThemeModeAuto = false;
$adminUpdateStatus = ['available' => false, 'version' => ''];
$activeAdminPodcast = null;
if ($isLoggedIn) {
    // Esta comprobación puede abrir una transacción de escritura. Debe ejecutarse
    // antes de mantener cursores de lectura abiertos en otra conexión SQLite.
    $adminUpdateStatus = loadDailyAdminUpdateStatus($dbPath);
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $podcastId = activePodcastId($pdo);
        $activeAdminPodcast = podcastById($pdo, $podcastId);
        $stmt = $pdo->prepare('SELECT app_language, admin_theme, public_theme_mode_auto FROM podcast WHERE id = :podcast_id LIMIT 1');
        $stmt->execute([':podcast_id' => $podcastId]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            if (is_string($row['app_language'] ?? null) && $row['app_language'] !== '') {
                $currentAppLanguage = $row['app_language'];
            }
            if (is_string($row['admin_theme'] ?? null) && isset(ADMIN_THEMES[$row['admin_theme']])) {
                $currentAdminTheme = $row['admin_theme'];
            }
            $currentPublicThemeModeAuto = ((int) ($row['public_theme_mode_auto'] ?? 0)) === 1;
        }
        $stmt->closeCursor();
        $pdo = null;
    } catch (Throwable $e) {
        // Usa el fallback.
    }
}
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Administración') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body class="<?= $isLoggedIn ? '' : 'login-page' ?>">
  <?php if ($isLoggedIn): ?>
    <?php $currentAdminPage = 'dashboard'; require __DIR__ . '/admin_nav.php'; ?>
    <div class="admin-wrap">
      <main class="card">
        <h1><?= esc(__('Panel de administración del Podcast %s', (string) ($activeAdminPodcast['title'] ?? __('Podcast')))) ?></h1>
        <p><?= __('Sesión iniciada como') ?> <strong><?= esc((string) $_SESSION['admin_user']) ?></strong>.</p>

        <?php if ($error !== ''): ?>
          <div class="error"><?= esc($error) ?></div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
          <div class="notice"><?= esc($notice) ?></div>
        <?php endif; ?>

        <?php if ($adminUpdateStatus['available']): ?>
          <div class="update-status-warning admin-update-notice">
            <?= __('Hay una nueva versión de EasyPodcast disponible:') ?>
            <strong>v<?= esc($adminUpdateStatus['version']) ?></strong>.
            <a href="update.php" class="update-footer-link"><?= __('Actualizar ahora') ?></a>
          </div>
        <?php endif; ?>

        <div class="admin-cards">
          <a class="admin-card" href="podcast_management.php">
            <div class="admin-card-icon">🎙</div>
            <h2><?= __('Podcast') ?></h2>
            <p><?= __('Metadatos del canal, imagen y categorías') ?></p>
          </a>
          <a class="admin-card" href="episodes_management.php">
            <div class="admin-card-icon">📻</div>
            <h2><?= __('Capítulos') ?></h2>
            <p><?= __('Lista, edita y borra episodios') ?></p>
          </a>
          <a class="admin-card" href="add_episode.php">
            <div class="admin-card-icon">➕</div>
            <h2><?= __('Añadir capítulo') ?></h2>
            <p><?= __('Sube un nuevo episodio al podcast') ?></p>
          </a>
          <a class="admin-card" href="social_management.php">
            <div class="admin-card-icon">🔗</div>
            <h2><?= __('Redes Sociales') ?></h2>
            <p><?= __('Blog, LinkedIn, Mastodon, X y más') ?></p>
          </a>
          <a class="admin-card" href="pages_management.php">
            <div class="admin-card-icon">📄</div>
            <h2><?= __('Páginas') ?></h2>
            <p><?= __('Crea páginas estáticas tipo "Acerca de"') ?></p>
          </a>
          <a class="admin-card" href="stats.php">
            <div class="admin-card-icon">📊</div>
            <h2><?= __('Estadísticas') ?></h2>
            <p><?= __('Episodios, borradores, tamaño de audios y descargas/reproducciones') ?></p>
          </a>
          <a class="admin-card" href="import_feed.php">
            <div class="admin-card-icon">📥</div>
            <h2><?= __('Importar feed RSS') ?></h2>
            <p><?= __('Importa episodios desde una URL de feed RSS externo') ?></p>
          </a>
          <a class="admin-card" href="media_cleanup.php">
            <div class="admin-card-icon">🧹</div>
            <h2><?= __('Limpiar') ?></h2>
            <p><?= __('Borra audios e imágenes que no usa ningún episodio') ?></p>
          </a>
          <?php if (!$adminMultipodcastEnabled): ?>
          <a class="admin-card" href="multipodcast.php">
            <div class="admin-card-icon">🎧</div>
            <h2><?= __('Multipodcast') ?></h2>
            <p><?= __('Crea, selecciona y configura los podcasts de la instalación') ?></p>
          </a>
          <a class="admin-card" href="backups.php">
            <div class="admin-card-icon">💾</div>
            <h2><?= __('Backups') ?></h2>
            <p><?= __('Exporta e importa la base de datos') ?></p>
          </a>
          <a class="admin-card" href="cache_management.php">
            <div class="admin-card-icon">⚡</div>
            <h2><?= __('Caché') ?></h2>
            <p><?= __('Habilita la caché, bórrala o regenera imágenes') ?></p>
          </a>
          <a class="admin-card" href="twofa_management.php">
            <div class="admin-card-icon">🔐</div>
            <h2><?= __('2FA') ?></h2>
            <p><?= __('Autenticación en dos pasos con código TOTP') ?></p>
          </a>
          <a class="admin-card" href="change_password.php">
            <div class="admin-card-icon">🔑</div>
            <h2><?= __('Contraseña') ?></h2>
            <p><?= __('Cambia la contraseña de acceso al panel') ?></p>
          </a>
          <a class="admin-card" href="update.php">
            <div class="admin-card-icon">⬆️</div>
            <h2><?= __('Actualizar') ?></h2>
            <p><?= __('Comprueba e instala nuevas versiones') ?></p>
          </a>
          <a class="admin-card" href="api_tokens.php">
            <div class="admin-card-icon">🔌</div>
            <h2>API Tokens</h2>
            <p><?= __('Genera y revoca tokens para la API REST') ?></p>
          </a>
          <?php endif; ?>
          <a class="admin-card" href="<?= esc($activeAdminPodcast !== null ? podcastPath($activeAdminPodcast, '', multipodcastEnabled(openPodcastDatabase($dbPath))) : '/') ?>" target="_blank" rel="noopener">
            <div class="admin-card-icon">🌐</div>
            <h2><?= __('Ver podcast') ?></h2>
            <p><?= __('Abre la web pública en una pestaña nueva') ?></p>
          </a>
          <?php
            $localeLabels = [
                'ca_ES' => 'Català',
                'de_DE' => 'Deutsch',
                'en_US' => 'English',
                'es_ES' => 'Español',
                'fr_FR' => 'Français',
                'gl_ES' => 'Galego',
                'it_IT' => 'Italiano',
                'pt_PT' => 'Português',
            ];
            $localeFiles = glob(__DIR__ . '/locale/*.po') ?: [];
            sort($localeFiles);
          ?>
          <form method="post" action="admin.php" class="admin-card admin-card-form">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_language">
            <div class="admin-card-icon">🌐</div>
            <h2><?= __('Idioma') ?></h2>
            <select name="app_language" data-submit-on-change="1">
              <?php foreach ($localeFiles as $f):
                $lc = basename($f, '.po');
                $label = $localeLabels[$lc] ?? $lc;
              ?>
                <option value="<?= esc($lc) ?>" <?= $currentAppLanguage === $lc ? 'selected' : '' ?>><?= esc($label) ?></option>
              <?php endforeach; ?>
            </select>
          </form>

          <form method="post" action="admin.php" class="admin-card admin-card-form">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="set_theme">
            <div class="admin-card-icon">🎨</div>
            <h2><?= __('Apariencia') ?></h2>
            <select name="app_theme" data-submit-on-change="1">
              <?php foreach (ADMIN_THEMES as $slug => $themeLabel): ?>
                <option value="<?= esc($slug) ?>" <?= $currentAdminTheme === $slug ? 'selected' : '' ?>><?= esc($themeLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="inline-checkbox">
              <input type="checkbox" name="public_theme_mode_auto" value="1" data-submit-on-change="1" <?= $currentPublicThemeModeAuto ? 'checked' : '' ?>>
              <span><?= __('Según sistema') ?></span>
            </label>
          </form>
        </div>

        <div class="actions section-gap-lg justify-end">
          <form method="post" action="admin.php" class="inline-display">
            <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn logout" type="submit"><?= __('Cerrar sesión') ?></button>
          </form>
        </div>
      </main>
    </div>
  <?php elseif ($isTotpPending): ?>
    <main class="card">
      <h1><?= __('Verificación en dos pasos') ?></h1>
      <p><?= __('Introduce el código de 6 dígitos de tu app de autenticación, o uno de tus códigos de recuperación.') ?></p>

      <?php if ($totpError !== ''): ?>
        <div class="error"><?= esc($totpError) ?></div>
      <?php endif; ?>

      <form method="post" action="admin.php" autocomplete="off" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <label>
          <?= __('Código') ?>
            <input type="text" name="totp_code" inputmode="numeric" maxlength="9"
                 autocomplete="one-time-code" autofocus placeholder="000000" class="totp-code-input">
        </label>
        <label class="inline-option-row">
          <input type="checkbox" name="remember_device" value="1">
          <?= __('Recordar este dispositivo durante 7 días') ?>
        </label>
        <button class="btn" type="submit"><?= __('Verificar') ?></button>
      </form>
      <div class="section-gap-sm text-right">
        <form method="post" action="admin.php" class="inline-display">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="link-muted"><?= __('Cancelar y volver al login') ?></button>
        </form>
      </div>
    </main>
  <?php else: ?>
    <main class="card">
      <h1><?= $isSetupMode ? __('Configuración inicial') : __('Acceso administrador') ?></h1>
      <p>
        <?= $isSetupMode
            ? __('No hay usuario administrador. Crea el primero para proteger el panel.')
            : __('Introduce tus credenciales para entrar al panel de administración.') ?>
      </p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="admin.php" autocomplete="off" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
        <label>
          <?= __('Usuario') ?>
          <input type="text" name="username" maxlength="120" required>
        </label>
        <label>
          <?= __('Contraseña') ?>
          <input type="password" name="password" required>
        </label>

        <?php if ($isSetupMode): ?>
          <label>
            <?= __('Repite la contraseña') ?>
            <input type="password" name="password_confirm" required>
          </label>
        <?php endif; ?>

        <button class="btn" type="submit"><?= $isSetupMode ? __('Crear usuario y entrar') : __('Entrar') ?></button>
      </form>
    </main>
  <?php endif; ?>
</body>
</html>
