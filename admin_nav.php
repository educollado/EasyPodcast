<?php
// admin_nav.php — Barra de navegación compartida del panel de administración.
// Requiere que $currentAdminPage esté definida antes de incluir.
// Valores válidos: 'dashboard' | 'podcast' | 'episodes' | 'add' | 'backups' | 'stats'

require_once __DIR__ . '/lib/version.php';
$_navPage = $currentAdminPage ?? '';
?>
<nav class="admin-nav" aria-label="<?= esc(__('Navegación del panel')) ?>">
  <a class="admin-nav-brand" href="admin.php">EasyPodcast <small>v<?= APP_VERSION ?></small></a>
  <div class="admin-nav-links">
    <a class="admin-nav-link <?= $_navPage === 'dashboard' ? 'active' : '' ?>" href="admin.php"><?= __('Panel') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'podcast'   ? 'active' : '' ?>" href="podcast_management.php"><?= __('Podcast') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'episodes'  ? 'active' : '' ?>" href="episodes_management.php"><?= __('Capítulos') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'add'       ? 'active' : '' ?>" href="add_episode.php"><?= __('Añadir') ?></a>
    <a class="admin-nav-link <?= $_navPage === 'stats' ? 'active' : '' ?>" href="stats.php"><?= __('Estadísticas') ?></a>
    <a class="admin-nav-link" href="/" target="_blank" rel="noopener"><?= __('Ver web ↗') ?></a>
  </div>
  <form method="post" action="admin.php" class="admin-nav-logout-form">
    <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="admin-nav-logout"><?= __('Salir') ?></button>
  </form>
</nav>
<script src="/assets/js/admin.js"></script>
