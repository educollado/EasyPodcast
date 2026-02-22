<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/seo_helpers.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';
require_once __DIR__ . '/lib/search_query.php';
require_once __DIR__ . '/lib/search_seo.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

$query         = trim((string) ($_GET['q'] ?? ''));
$requestedPage = max(1, (int) ($_GET['page'] ?? 1));

$data = loadSearchData($dbPath, $query, $requestedPage);
extract($data);  // podcast, episodes, query, page, perPage, totalEpisodes, totalPages, error

$seo = buildSearchSeoData($podcast, $query, $page, $totalPages);
extract($seo);   // podcastTitle, podcastAuthor, podcastDescription, podcastImage,
                 // baseSeoUrl, canonicalUrl, robotsContent, prevUrl, nextUrl,
                 // metaDescription, ogImage, rssUrl

header('X-Robots-Tag: noindex, follow, noarchive');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Buscar | <?= esc($podcastTitle) ?></title>
  <meta name="robots" content="<?= esc($robotsContent) ?>">
  <meta name="description" content="<?= esc($metaDescription) ?>">
  <link rel="canonical" href="<?= esc($canonicalUrl) ?>">
  <?php if ($prevUrl !== null): ?>
  <link rel="prev" href="<?= esc($prevUrl) ?>">
  <?php endif; ?>
  <?php if ($nextUrl !== null): ?>
  <link rel="next" href="<?= esc($nextUrl) ?>">
  <?php endif; ?>
  <link rel="alternate" type="application/rss+xml" title="<?= esc($podcastTitle) ?> RSS" href="<?= esc($rssUrl) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= esc($podcastTitle) ?>">
  <meta property="og:title" content="Buscar | <?= esc($podcastTitle) ?>">
  <meta property="og:description" content="<?= esc($metaDescription) ?>">
  <meta property="og:url" content="<?= esc($canonicalUrl) ?>">
  <meta property="og:image" content="<?= esc($ogImage) ?>">
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico">
  <?php // Aplica el tema guardado ANTES de cargar el CSS para evitar parpadeo (FOUC). ?>
  <script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="/assets/css/index.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/dark.css">
</head>
<body>
  <div class="container">
    <?php $searchQuery = $query; ?>
    <?php require __DIR__ . '/header.php'; ?>

    <main class="card">
      <?php if ($error !== ''): ?>
        <p class="error"><?= esc($error) ?></p>
      <?php elseif ($query === ''): ?>
        <p class="empty">Escribe un término para buscar episodios.</p>
      <?php elseif (!$episodes): ?>
        <p class="empty">No hay resultados para "<?= esc($query) ?>".</p>
      <?php else: ?>
        <p class="meta">Resultados para "<?= esc($query) ?>": <?= (int) $totalEpisodes ?></p>
        <?php foreach ($episodes as $episode): ?>
          <article class="episode">
            <?php $episodeImage = trim((string) ($episode['image_url'] ?? '')); ?>
            <?php $cover = $episodeImage !== '' ? $episodeImage : $podcastImage; ?>
            <?php $coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144, 220]) : ['src' => '', 'srcset' => '']; ?>
            <?php if ($cover !== ''): ?>
              <img class="cover" src="<?= esc($coverSources['src'] !== '' ? $coverSources['src'] : $cover) ?>"<?php if ($coverSources['srcset'] !== ''): ?> srcset="<?= esc($coverSources['srcset']) ?>" sizes="(max-width: 460px) 180px, (max-width: 620px) 108px, 144px"<?php endif; ?> alt="Portada del capítulo">
            <?php else: ?>
              <div class="cover" aria-hidden="true"></div>
            <?php endif; ?>
            <div class="episode-content">
              <?php $episodeTitle = (string) ($episode['title'] ?? 'Sin título'); ?>
              <?php $episodeHref = resolveEpisodeHref((string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), $episodeTitle); ?>
              <?php $excerpt = firstChars((string) ($episode['description'] ?? ''), 240); ?>
              <h2><a href="<?= esc($episodeHref) ?>"><?= esc($episodeTitle) ?></a></h2>
              <p class="meta"><?= esc(formatPublishedDate((string) ($episode['pub_date'] ?? ''))) ?></p>
              <p class="excerpt">
                <?= esc((string) $excerpt['text']) ?>
                <?php if (!empty($excerpt['truncated'])): ?>
                  <a class="read-more" href="<?= esc($episodeHref) ?>">[Leer más]</a>
                <?php endif; ?>
              </p>
              <?php if (!empty($episode['audio_url'])): ?>
                <audio class="player" controls preload="none" src="<?= esc((string) $episode['audio_url']) ?>">
                  Tu navegador no soporta audio HTML5.
                </audio>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if ($totalPages > 1): ?>
          <nav class="pagination" aria-label="Paginación de resultados">
            <span>Página <?= (int) $page ?> de <?= (int) $totalPages ?></span>
            <div class="links">
              <?php if ($page > 1): ?>
                <?php $prevParams = ['q' => $query, 'page' => $page - 1]; ?>
                <a class="page-link" href="/search.php?<?= esc(http_build_query($prevParams)) ?>">Anterior</a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php $pageParams = ['q' => $query, 'page' => $p]; ?>
                <a class="page-link<?= $p === $page ? ' active' : '' ?>" href="/search.php?<?= esc(http_build_query($pageParams)) ?>"><?= $p ?></a>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <?php $nextParams = ['q' => $query, 'page' => $page + 1]; ?>
                <a class="page-link" href="/search.php?<?= esc(http_build_query($nextParams)) ?>">Siguiente</a>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </main>
    <?php require __DIR__ . '/footer.php'; ?>
  </div>
</body>
</html>
