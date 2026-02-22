<?php

declare(strict_types=1);

$podcastTitle = isset($podcastTitle) ? (string) $podcastTitle : 'Podcast';
$podcastAuthor = isset($podcastAuthor) ? (string) $podcastAuthor : '';
$podcastDescription = isset($podcastDescription) ? (string) $podcastDescription : '';
$searchQuery = isset($searchQuery) ? (string) $searchQuery : '';
?>
<header class="card">
  <div class="podcast-header">
    <div class="podcast-header-left header-box">
      <h1><a href="/"><?= esc($podcastTitle) ?></a></h1>
      <?php if ($podcastAuthor !== ''): ?>
        <p class="author"><?= esc($podcastAuthor) ?></p>
      <?php endif; ?>
      <?php if ($podcastDescription !== ''): ?>
        <p class="desc"><?= renderTextWithLinks($podcastDescription) ?></p>
      <?php endif; ?>
    </div>
    <div class="podcast-header-right header-box">
      <div style="display:flex;align-items:center;gap:.4rem;justify-content:flex-end;">
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="Cambiar modo claro/oscuro">🌙</button>
        <a class="rss-link" href="/feed.xml"><img src="/rss.png" alt="RSS"></a>
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

  function applyIcon() {
    btn.textContent = html.getAttribute('data-theme') === 'dark' ? '☀️' : '🌙';
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
