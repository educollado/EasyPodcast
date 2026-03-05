<?php
// admin_nav.php — Barra de navegación compartida del panel de administración.
// Requiere que $currentAdminPage esté definida antes de incluir.
// Valores válidos: 'dashboard' | 'podcast' | 'episodes' | 'add' | 'backups'

require_once __DIR__ . '/lib/version.php';
$_navPage = $currentAdminPage ?? '';
?>
<nav class="admin-nav" aria-label="Navegación del panel">
  <a class="admin-nav-brand" href="admin.php">EasyPodcast <small>v<?= APP_VERSION ?></small></a>
  <div class="admin-nav-links">
    <a class="admin-nav-link <?= $_navPage === 'dashboard' ? 'active' : '' ?>" href="admin.php">Panel</a>
    <a class="admin-nav-link <?= $_navPage === 'podcast'   ? 'active' : '' ?>" href="podcast_management.php">Podcast</a>
    <a class="admin-nav-link <?= $_navPage === 'episodes'  ? 'active' : '' ?>" href="episodes_management.php">Capítulos</a>
    <a class="admin-nav-link <?= $_navPage === 'add'       ? 'active' : '' ?>" href="add_episode.php">Añadir</a>
    <a class="admin-nav-link <?= $_navPage === 'backups'   ? 'active' : '' ?>" href="backups.php">Backups</a>
    <a class="admin-nav-link <?= $_navPage === 'cache'     ? 'active' : '' ?>" href="cache_management.php">Caché</a>
    <a class="admin-nav-link <?= $_navPage === 'twofa'    ? 'active' : '' ?>" href="twofa_management.php">2FA</a>
    <a class="admin-nav-link <?= $_navPage === 'social'    ? 'active' : '' ?>" href="social_management.php">Redes</a>
    <a class="admin-nav-link <?= $_navPage === 'password'  ? 'active' : '' ?>" href="change_password.php">Contraseña</a>
    <a class="admin-nav-link" href="/" target="_blank" rel="noopener">Ver web ↗</a>
  </div>
  <a class="admin-nav-logout" href="admin.php?logout=1">Salir</a>
</nav>
