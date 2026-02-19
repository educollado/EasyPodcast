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
      <a class="rss-link" href="/feed.xml"><img src="/rss.png" alt="RSS"></a>
      <form class="search-form" method="get" action="/search.php" role="search">
        <input type="search" name="q" value="<?= esc($searchQuery) ?>" placeholder="Buscar episodios" aria-label="Buscar episodios">
        <button type="submit">Buscar</button>
      </form>
    </div>
  </div>
</header>
