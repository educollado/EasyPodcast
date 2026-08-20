<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/social_handler.php';
require_once __DIR__ . '/feed_builder.php';
$_footerDbPath = isset($dbPath) ? $dbPath : (getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite');
$_footerSocial = getSocialLinks($_footerDbPath);
$_footerIcons  = getSocialIcons();
try {
    $_footerPdo = openPodcastDatabase($_footerDbPath);
    $_footerHomeUrl = resolveApplicationHomeUrl($_footerPdo);
    $_footerShowHomeLink = !(multipodcastEnabled($_footerPdo) && activePodcast($_footerPdo) === null);
} catch (Throwable $e) {
    $_footerHomeUrl = runtimeBaseUrl();
    $_footerShowHomeLink = true;
}
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
  <?php if ($_footerShowHomeLink): ?>
    <p class="footer-home-link"><?= esc(__('Podcast creado dentro de')) ?> <a href="<?= esc($_footerHomeUrl) ?>"><?= esc($_footerHomeUrl) ?></a></p>
  <?php endif; ?>
</footer>
<script src="/assets/js/public.js"></script>
