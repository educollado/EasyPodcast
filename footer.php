<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/social_handler.php';
$_footerDbPath  = isset($dbPath) ? $dbPath : (getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite');
$_footerSocial  = getSocialLinks($_footerDbPath);
$_mastodonUrl   = (string) ($_footerSocial['mastodon'] ?? '');
?>
<footer class="site-footer">
  <a href="https://github.com/educollado/EasyPodcast" target="_blank" rel="noopener noreferrer">EasyPodcast</a>, hecho en <strong>Europa</strong> con ❤️ por <a href="https://www.eduardocollado.com" target="_blank" rel="noopener noreferrer">Eduardo Collado</a>
  <?php if ($_mastodonUrl !== ''): ?>
    <span style="font-size:0;"><a rel="me" href="<?= esc($_mastodonUrl) ?>">Mastodon</a></span>
  <?php endif; ?>
</footer>
