<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/social_handler.php';
$_footerDbPath = isset($dbPath) ? $dbPath : (getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite');
$_footerSocial = getSocialLinks($_footerDbPath);
$_footerIcons  = getSocialIcons();
?>
<footer class="site-footer">
  <?php
    $_hasSocial = false;
    foreach ($_footerIcons as $_fKey => $_fData) {
        if ((string) ($_footerSocial[$_fKey] ?? '') !== '') { $_hasSocial = true; break; }
    }
  ?>
  <?php if ($_hasSocial): ?>
    <div class="footer-social">
      <?php foreach ($_footerIcons as $_fKey => $_fData): ?>
        <?php $_fUrl = (string) ($_footerSocial[$_fKey] ?? ''); ?>
        <?php if ($_fUrl !== ''): ?>
          <a class="footer-social-link" href="<?= esc($_fUrl) ?>" target="_blank"
             rel="noopener noreferrer me" aria-label="<?= esc($_fData['label']) ?>"><?= $_fData['svg'] ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p><?= __('Podcast creado con %s, hecho en %s con ❤️ por %s',
    '<a href="https://www.easypodcast.eu" target="_blank" rel="noopener noreferrer">EasyPodcast</a>',
    '<strong>Europa</strong>',
    '<a href="https://www.eduardocollado.com" target="_blank" rel="noopener noreferrer">Eduardo Collado</a>'
  ) ?></p>
</footer>
<script src="/assets/js/public.js"></script>
