<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/page_helpers.php';
require_once __DIR__ . '/lib/admin_theme.php';

$podcastTitle = isset($podcastTitle) ? (string) $podcastTitle : 'Podcast';
$podcastAuthor = isset($podcastAuthor) ? (string) $podcastAuthor : '';
$podcastDescription = isset($podcastDescription) ? (string) $podcastDescription : '';
$podcastImage = isset($podcastImage) ? (string) $podcastImage : '';
$searchQuery = isset($searchQuery) ? (string) $searchQuery : '';
$normalThemeModeUrl = buildPublicThemeModeUrl('normal');
$autoThemeModeUrl = buildPublicThemeModeUrl('auto');
// Variantes 80px (normal) y 144px (retina) para la miniatura de cabecera.
$headerImgSources = $podcastImage !== '' ? buildResponsiveSquareImageSources($podcastImage, [80, 144]) : ['src' => '', 'srcset' => ''];
?>
<header class="card">
  <div class="podcast-header">
    <div class="podcast-header-left header-box">
      <div class="podcast-branding">
        <?php if ($headerImgSources['src'] !== ''): ?>
          <a href="/" aria-label="<?= esc(__('Ir a la página de inicio')) ?>">
            <img class="podcast-cover-header"
                 src="<?= esc($headerImgSources['src']) ?>"
                 <?php if ($headerImgSources['srcset'] !== ''): ?>srcset="<?= esc($headerImgSources['srcset']) ?>" sizes="(max-width: 460px) 64px, 80px"<?php endif; ?>
                 width="80" height="80" alt="<?= esc(__('Portada del podcast')) ?>">
          </a>
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
      <div class="header-controls">
        <nav class="theme-mode-switch" aria-label="<?= esc(__('Modo de visualización')) ?>">
          <span class="theme-mode-link"><?= __('Tema:') ?></span>
          <a class="theme-mode-link theme-mode-link-normal" href="<?= esc($normalThemeModeUrl) ?>"><?= __('Normal') ?></a>
          <span class="theme-mode-separator"> | </span>
          <a class="theme-mode-link theme-mode-link-auto" href="<?= esc($autoThemeModeUrl) ?>"><?= __('Según sistema') ?></a>
        </nav>
        <a class="rss-link" href="/feed.xml" aria-label="<?= esc(__('Feed RSS')) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
            <circle cx="5" cy="19" r="3"/>
            <path d="M4 4a16 16 0 0 1 16 16h-3A13 13 0 0 0 4 7z"/>
            <path d="M4 11a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6z"/>
          </svg>
        </a>
      </div>
      <form class="search-form" method="get" action="/search.php" role="search">
        <input type="search" name="q" value="<?= esc($searchQuery) ?>" placeholder="<?= esc(__('Buscar episodios')) ?>" aria-label="<?= esc(__('Buscar episodios')) ?>">
        <button type="submit"><?= __('Buscar') ?></button>
      </form>
    </div>
  </div>
</header>
<?php
// Barra de navegación: siempre incluye "Inicio" seguido de las páginas publicadas.
$_navPages = isset($dbPath) ? getPublishedPagesForNav($dbPath) : [];
?>
<nav class="pages-nav" aria-label="Navegación principal">
  <div class="pages-nav-item">
    <a href="/"><?= __('Inicio') ?></a>
  </div>
  <?php foreach ($_navPages as $_navPage): ?>
    <?php if ($_navPage['children']): ?>
      <div class="pages-nav-item has-submenu">
        <a href="/<?= esc((string) $_navPage['full_path']) ?>"><?= esc((string) $_navPage['title']) ?></a>
        <ul class="pages-submenu">
          <?php foreach ($_navPage['children'] as $_navChild): ?>
            <li><a href="/<?= esc((string) $_navChild['full_path']) ?>"><?= esc((string) $_navChild['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="pages-nav-item">
        <a href="/<?= esc((string) $_navPage['full_path']) ?>"><?= esc((string) $_navPage['title']) ?></a>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
