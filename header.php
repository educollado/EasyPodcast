<?php

declare(strict_types=1);

$podcastTitle = isset($podcastTitle) ? (string) $podcastTitle : 'Podcast';
$podcastAuthor = isset($podcastAuthor) ? (string) $podcastAuthor : '';
$podcastDescription = isset($podcastDescription) ? (string) $podcastDescription : '';
$podcastImage = isset($podcastImage) ? (string) $podcastImage : '';
$searchQuery = isset($searchQuery) ? (string) $searchQuery : '';
// Variantes 80px (normal) y 144px (retina) para la miniatura de cabecera.
$headerImgSources = $podcastImage !== '' ? buildResponsiveSquareImageSources($podcastImage, [80, 144]) : ['src' => '', 'srcset' => ''];
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
