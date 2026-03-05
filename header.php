<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/social_handler.php';

$podcastTitle = isset($podcastTitle) ? (string) $podcastTitle : 'Podcast';
$podcastAuthor = isset($podcastAuthor) ? (string) $podcastAuthor : '';
$podcastDescription = isset($podcastDescription) ? (string) $podcastDescription : '';
$podcastImage = isset($podcastImage) ? (string) $podcastImage : '';
$searchQuery = isset($searchQuery) ? (string) $searchQuery : '';
// Variantes 80px (normal) y 144px (retina) para la miniatura de cabecera.
$headerImgSources = $podcastImage !== '' ? buildResponsiveSquareImageSources($podcastImage, [80, 144]) : ['src' => '', 'srcset' => ''];

// Redes sociales configuradas.
$_dbPathForSocial = isset($dbPath) ? $dbPath : (getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite');
$_socialLinks = getSocialLinks($_dbPathForSocial);

// SVG icons para cada red social (20×20, currentColor).
$_socialIcons = [
    'blog' => [
        'label' => 'Blog',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
    ],
    'linkedin' => [
        'label' => 'LinkedIn',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>',
    ],
    'mastodon' => [
        'label' => 'Mastodon',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M21.33 8.57c0-4.34-2.84-5.61-2.84-5.61-1.43-.66-3.9-.94-6.45-.96h-.06c-2.56.02-5.02.3-6.45.96 0 0-2.84 1.27-2.84 5.61 0 .99-.02 2.18.01 3.44.1 4.24.78 8.43 4.7 9.46 1.81.48 3.36.58 4.61.51 2.27-.13 3.54-.81 3.54-.81l-.07-1.65s-1.62.51-3.44.45c-1.8-.06-3.71-.19-4-2.41a4.52 4.52 0 0 1-.04-.62s1.77.43 4.01.54c1.37.06 2.66-.08 3.97-.24 2.5-.3 4.68-1.84 4.96-3.25.43-2.22.4-5.42.4-5.42zm-3.35 5.59h-2.08V9.06c0-1.07-.45-1.62-1.36-1.62-1 0-1.5.65-1.5 1.93v2.79h-2.07V9.36c0-1.28-.5-1.93-1.5-1.93-.9 0-1.36.55-1.36 1.62v5.1H6.03V8.9c0-1.07.27-1.93.82-2.56.57-.63 1.31-.95 2.24-.95 1.07 0 1.88.41 2.42 1.23l.52.87.52-.87c.53-.82 1.34-1.23 2.41-1.23.93 0 1.67.32 2.24.95.55.63.82 1.49.82 2.56v5.26z"/></svg>',
    ],
    'x' => [
        'label' => 'X',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.402 6.231H2.746l7.73-8.835L1.254 2.25H8.08l4.259 5.626L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>',
    ],
    'pixelfed' => [
        'label' => 'Pixelfed',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>',
    ],
    'instagram' => [
        'label' => 'Instagram',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
    ],
    'youtube' => [
        'label' => 'YouTube',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="#fff"/></svg>',
    ],
    'github' => [
        'label' => 'GitHub',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0 1 12 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0 0 22 12.017C22 6.484 17.522 2 12 2z"/></svg>',
    ],
    'bluesky' => [
        'label' => 'Bluesky',
        'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.04.415-.057-.138.022-.276.04-.415.057-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 0 1-.415-.057c.14.017.279.036.415.057 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.479 0-.69-.139-1.861-.902-2.206-.659-.299-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8z"/></svg>',
    ],
];
?>
<header class="card">
  <div class="podcast-header">
    <div class="podcast-header-left header-box">
      <div class="podcast-branding">
        <?php if ($headerImgSources['src'] !== ''): ?>
          <img class="podcast-cover-header"
               src="<?= esc($headerImgSources['src']) ?>"
               <?php if ($headerImgSources['srcset'] !== ''): ?>srcset="<?= esc($headerImgSources['srcset']) ?>" sizes="(max-width: 460px) 64px, 80px"<?php endif; ?>
               width="80" height="80" alt="Portada del podcast">
        <?php endif; ?>
        <div class="podcast-info">
          <h1><a href="/"><?= esc($podcastTitle) ?></a></h1>
          <?php if ($podcastAuthor !== ''): ?>
            <p class="author"><?= esc($podcastAuthor) ?></p>
          <?php endif; ?>
          <?php if ($podcastDescription !== ''): ?>
            <p class="desc"><?= renderTextWithLinks($podcastDescription) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="podcast-header-right header-box">
      <div style="display:flex;align-items:center;gap:.4rem;justify-content:flex-end;">
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="Cambiar modo claro/oscuro"></button>
        <a class="rss-link" href="/feed.xml" aria-label="Feed RSS">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
            <circle cx="5" cy="19" r="3"/>
            <path d="M4 4a16 16 0 0 1 16 16h-3A13 13 0 0 0 4 7z"/>
            <path d="M4 11a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6z"/>
          </svg>
        </a>
      </div>
      <form class="search-form" method="get" action="/search.php" role="search">
        <input type="search" name="q" value="<?= esc($searchQuery) ?>" placeholder="Buscar episodios" aria-label="Buscar episodios">
        <button type="submit">Buscar</button>
      </form>
      <?php
        $_hasSocial = false;
        foreach ($_socialIcons as $_sKey => $_sData) {
            if ((string) ($_socialLinks[$_sKey] ?? '') !== '') { $_hasSocial = true; break; }
        }
      ?>
      <?php if ($_hasSocial): ?>
        <div style="display:flex;align-items:center;gap:.4rem;justify-content:flex-end;flex-wrap:wrap;">
          <?php foreach ($_socialIcons as $_sKey => $_sData): ?>
            <?php $_sUrl = (string) ($_socialLinks[$_sKey] ?? ''); ?>
            <?php if ($_sUrl !== ''): ?>
              <a class="social-link" href="<?= esc($_sUrl) ?>" target="_blank" rel="noopener noreferrer me"
                 aria-label="<?= esc($_sData['label']) ?>"><?= $_sData['svg'] ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</header>
<script>
// Toggle de modo oscuro.
// El script vive aquí (justo tras el botón en el DOM) para poder adjuntar
// el listener sin esperar DOMContentLoaded.
// La preferencia se persiste en localStorage; la BD no se toca.
(function () {
  var btn  = document.getElementById('themeToggle');
  var html = document.documentElement;

  // SVG luna (negro) para modo claro; SVG sol (amarillo) para modo oscuro.
  var MOON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><path fill="#1d2a33" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
  var SUN  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="4" fill="#f5c518"/><path stroke="#f5c518" stroke-width="2" stroke-linecap="round" fill="none" d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>';

  function applyIcon() {
    btn.innerHTML = html.getAttribute('data-theme') === 'dark' ? SUN : MOON;
  }

  btn.addEventListener('click', function () {
    if (html.getAttribute('data-theme') === 'dark') {
      html.removeAttribute('data-theme');
      localStorage.removeItem('theme');
    } else {
      html.setAttribute('data-theme', 'dark');
      localStorage.setItem('theme', 'dark');
    }
    applyIcon();
  });

  applyIcon();
}());
</script>
