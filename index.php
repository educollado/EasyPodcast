<?php

declare(strict_types=1);

// Portada pública:
// - lista solo episodios publicados
// - soporta paginación
// - enlaza cada título a su URL amigable

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/seo_helpers.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';
require_once __DIR__ . '/lib/home_query.php';
require_once __DIR__ . '/lib/home_seo.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
if (tryServeWebCache($dbPath, 'text/html; charset=UTF-8')) {
    exit;
}
ob_start();

$requestedPage = max(1, (int) ($_GET['page'] ?? 1));

$data = loadHomeData($dbPath, $requestedPage);
extract($data);  // podcast, episodes, page, perPage, totalEpisodes, totalPages, error

$seo = buildHomeSeoData($podcast, $page, $totalPages, $error);
extract($seo);   // podcastTitle, podcastAuthor, podcastDescription, podcastImage,
                 // baseSeoUrl, canonicalUrl, robotsContent, prevUrl, nextUrl,
                 // metaDescription, ogImage, rssUrl, seriesJsonLd

if ($error !== '') {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($podcastTitle) ?></title>
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
  <meta property="og:title" content="<?= esc($podcastTitle) ?>">
  <meta property="og:description" content="<?= esc($metaDescription) ?>">
  <meta property="og:url" content="<?= esc($canonicalUrl) ?>">
  <meta property="og:image" content="<?= esc($ogImage) ?>">
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <?php // Aplica el tema guardado ANTES de cargar el CSS para evitar parpadeo (FOUC). ?>
  <script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/index.css">
  <link rel="stylesheet" href="/assets/css/dark.css">
  <script type="application/ld+json"><?= $seriesJsonLd ?></script>
</head>
<body>
  <div class="container">
    <?php require __DIR__ . '/header.php'; ?>

    <main class="card">
      <?php if ($error !== ''): ?>
        <p class="error"><?= esc($error) ?></p>
      <?php elseif (!$episodes): ?>
        <p class="empty">Todavía no hay capítulos publicados.</p>
      <?php else: ?>
        <?php foreach ($episodes as $episode): ?>
          <article class="episode reveal">
            <?php $episodeImage = trim((string) ($episode['image_url'] ?? '')); ?>
            <?php // Usa portada del podcast cuando falta la portada del episodio. ?>
            <?php $cover = $episodeImage !== '' ? $episodeImage : $podcastImage; ?>
            <?php // Genera srcset responsive de miniaturas cuadradas y reutiliza variantes existentes. ?>
            <?php $episodeTitle = (string) ($episode['title'] ?? 'Sin título'); ?>
            <?php $episodeHref = resolveEpisodeHref((string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), $episodeTitle); ?>
            <?php $coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144,220]) : ['src' => '', 'srcset' => '']; ?>
            <?php if ($cover !== ''): ?>
              <a href="<?= esc($episodeHref) ?>" tabindex="-1" aria-hidden="true">
                <img class="cover" src="<?= esc($coverSources['src'] !== '' ? $coverSources['src'] : $cover) ?>"<?php if ($coverSources['srcset'] !== ''): ?> srcset="<?= esc($coverSources['srcset']) ?>" sizes="(max-width: 460px) 160px, (max-width: 620px) 88px, 112px"<?php endif; ?> alt="Portada del capítulo">
              </a>
            <?php else: ?>
              <div class="cover" aria-hidden="true"></div>
            <?php endif; ?>
            <div class="episode-content">
              <?php $excerpt = firstChars(stripMarkdown((string) ($episode['description'] ?? '')), 200); ?>
              <h2><a href="<?= esc($episodeHref) ?>"><?= esc($episodeTitle) ?></a></h2>
              <p class="meta">
                <?= esc(formatPublishedDate((string) ($episode['pub_date'] ?? ''))) ?>
              </p>
              <p class="excerpt">
                <?= esc((string) $excerpt['text']) ?>
                <?php if (!empty($excerpt['truncated'])): ?>
                  <a class="read-more" href="<?= esc($episodeHref) ?>">Leer más</a>
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
          <nav class="pagination" aria-label="Paginación de capítulos">
            <span>Página <?= (int) $page ?> de <?= (int) $totalPages ?></span>
            <div class="links">
              <?php if ($page > 1): ?>
                <a class="page-link" href="index.php?page=<?= $page - 1 ?>">Anterior</a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="page-link<?= $p === $page ? ' active' : '' ?>" href="index.php?page=<?= $p ?>"><?= $p ?></a>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <a class="page-link" href="index.php?page=<?= $page + 1 ?>">Siguiente</a>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </main>
    <?php require __DIR__ . '/footer.php'; ?>
  </div>
  <script>
  (function () {
    if (!('IntersectionObserver' in window)) return;
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.style.opacity = '1';
          e.target.style.transform = 'translateY(0)';
          obs.unobserve(e.target);
        }
      });
    }, { threshold: 0.06 });
    document.querySelectorAll('.reveal').forEach(function (el) {
      el.style.transition = 'opacity .55s ease, transform .55s ease';
      el.style.opacity = '0';
      el.style.transform = 'translateY(24px)';
      obs.observe(el);
    });
  }());
  </script>
</body>
</html>
<?php
$cachedOutput = ob_get_contents();
if (is_string($cachedOutput)) {
    storeWebCache($dbPath, $cachedOutput);
}
ob_end_flush();
