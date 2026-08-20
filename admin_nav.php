<?php
// admin_nav.php — Barra de navegación compartida del panel de administración.
// Requiere que $currentAdminPage esté definida antes de incluir.
// Valores habituales: 'multipodcast' | 'dashboard' | 'podcast' | 'episodes' | 'add' | 'backups' | 'stats'

require_once __DIR__ . '/lib/version.php';
require_once __DIR__ . '/lib/podcast_context.php';
$_navPage = $currentAdminPage ?? '';
$navPodcast = $GLOBALS['_active_podcast'] ?? null;
$navMultipodcastEnabled = (bool) ($GLOBALS['_multipodcast_enabled'] ?? false);
$navMultipodcastPages = ['multipodcast', 'multipodcast_settings', 'podcasts', 'cache', 'update', 'password', 'twofa', 'backups', 'api_tokens', 'api_docs'];
$navPodcastScopedMultipodcastPages = ['cache', 'api_tokens', 'api_docs'];
$navIsMultipodcastArea = in_array($_navPage, ['multipodcast', 'multipodcast_settings', 'podcasts'], true)
    || ($navMultipodcastEnabled && in_array($_navPage, $navMultipodcastPages, true));
$navUsesPodcastContext = $navIsMultipodcastArea
    && in_array($_navPage, $navPodcastScopedMultipodcastPages, true);
$navMulti = $navMultipodcastEnabled || $navIsMultipodcastArea;
$navPublicUrl = !$navIsMultipodcastArea && is_array($navPodcast) ? podcastPath($navPodcast, '', true) : '/';
$navSelectorAction = match ($_navPage) {
    'cache' => 'cache_management.php',
    'api_tokens' => 'api_tokens.php',
    'api_docs' => 'api_docs.php',
    default => 'admin.php',
};
$navPodcasts = [];
if ($navMulti && isset($dbPath) && is_string($dbPath)) {
    try {
        $navPdo = openPodcastDatabase($dbPath);
        $navPodcasts = $navPdo->query(
            "SELECT id, title, slug FROM podcast WHERE slug IS NOT NULL AND slug != '' ORDER BY title COLLATE NOCASE ASC"
        )->fetchAll();
        $navPdo = null;
    } catch (Throwable $e) {
        $navPodcasts = [];
    }
}
$navActivePodcastId = (!$navIsMultipodcastArea || $navUsesPodcastContext) && is_array($navPodcast)
    ? (int) ($navPodcast['id'] ?? 0)
    : 0;
?>
<nav
  class="admin-nav<?= $navMulti ? ' is-multipodcast' : '' ?>"
  aria-label="<?= esc(__('Navegación del panel')) ?>"
  data-file-select-label="<?= esc(__('Seleccionar archivo')) ?>"
  data-file-empty-label="<?= esc(__('No se ha seleccionado ningún archivo')) ?>"
  data-file-multiple-label="<?= esc(__('%d archivos seleccionados')) ?>"
>
  <a class="admin-nav-brand" href="<?= $navMulti ? 'multipodcast.php' : 'admin.php' ?>">EasyPodcast <small>v<?= APP_VERSION ?></small></a>
  <div class="admin-nav-links">
    <?php if ($navMulti): ?>
      <form class="admin-nav-podcast-selector" method="get" action="<?= esc($navSelectorAction) ?>">
        <?php if (!$navUsesPodcastContext): ?><input type="hidden" name="manage" value="1"><?php endif; ?>
        <select name="podcast" data-submit-on-change="1" aria-label="<?= esc(__('Elegir podcast')) ?>">
          <option value="" data-navigation-url="multipodcast.php" <?= $navActivePodcastId === 0 ? 'selected' : '' ?>><?= __('Multipodcast') ?></option>
          <?php foreach ($navPodcasts as $navPodcastOption): ?>
            <option value="<?= esc((string) $navPodcastOption['slug']) ?>" <?= $navActivePodcastId === (int) $navPodcastOption['id'] ? 'selected' : '' ?>><?= esc((string) $navPodcastOption['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <?php if ($navIsMultipodcastArea): ?>
    <a class="admin-nav-link <?= in_array($_navPage, ['multipodcast', 'multipodcast_settings'], true) ? 'active' : '' ?>" href="multipodcast.php"><?= __('Administración') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'podcasts' ? 'active' : '' ?>" href="podcasts_management.php"><?= __('Podcasts') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'cache' ? 'active' : '' ?>" href="cache_management.php"><?= __('Caché') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'update' ? 'active' : '' ?>" href="update.php"><?= __('Actualizar') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'password' ? 'active' : '' ?>" href="change_password.php"><?= __('Contraseña') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'twofa' ? 'active' : '' ?>" href="twofa_management.php"><?= __('2FA') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'backups' ? 'active' : '' ?>" href="backups.php"><?= __('Backups') ?></a>
    <a class="admin-nav-link <?= in_array($_navPage, ['api_tokens', 'api_docs'], true) ? 'active' : '' ?>" href="api_tokens.php">API</a>
    <?php else: ?>
    <a class="admin-nav-link <?= $_navPage === 'dashboard' ? 'active' : '' ?>" href="admin.php<?= $navMulti ? '?manage=1' : '' ?>"><?= __('Administración') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'podcast'   ? 'active' : '' ?>" href="podcast_management.php"><?= __('Podcast') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'episodes'  ? 'active' : '' ?>" href="episodes_management.php"><?= __('Capítulos') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'add'       ? 'active' : '' ?>" href="add_episode.php"><?= __('Añadir') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'stats' ? 'active' : '' ?>" href="stats.php"><?= __('Estadísticas') ?></a>
    <?php endif; ?>
    <a class="admin-nav-link" href="<?= esc($navPublicUrl) ?>" target="_blank" rel="noopener"><?= $navIsMultipodcastArea ? __('Ver podcasts ↗') : __('Ver podcast ↗') ?></a>
  </div>
  <form method="post" action="admin.php" class="admin-nav-logout-form">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="admin-nav-logout"><?= __('Salir') ?></button>
  </form>
</nav>
<script src="/assets/js/admin.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/admin.js') ?>"></script>
