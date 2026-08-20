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
require_once __DIR__ . '/lib/social_handler.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
$contextPdo = openPodcastDatabase($dbPath);
if (multipodcastEnabled($contextPdo) && activePodcast($contextPdo) === null) {
    require __DIR__ . '/multipodcast_home.php';
    exit;
}
if (tryServeWebCache($dbPath, 'text/html; charset=UTF-8')) {
    exit;
}
ob_start();

$requestedPage = max(1, (int) ($_GET['page'] ?? 1));

$data = loadHomeData($dbPath, $requestedPage);
extract($data);  // podcast, episodes, page, perPage, totalEpisodes, totalPages, error

$seo = buildHomeSeoData($podcast, $page, $totalPages, $error);
extract($seo);

$_social = getSocialLinks($dbPath);
$_fediverseCreator = mastodonUrlToFediverseHandle((string) ($_social['mastodon'] ?? ''));   // podcastTitle, podcastAuthor, podcastDescription, podcastImage,
                 // baseSeoUrl, canonicalUrl, robotsContent, prevUrl, nextUrl,
                 // metaDescription, ogImage, rssUrl, seriesJsonLd

if ($error !== '') {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>" data-theme-mode="<?= esc(publicThemeMode()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($podcastTitle) ?></title>
  <meta name="robots" content="<?= esc($robotsContent) ?>">
  <meta name="description" content="<?= esc($metaDescription) ?>">
  <?php if ($_fediverseCreator !== ''): ?>
  <meta name="fediverse:creator" content="<?= esc($_fediverseCreator) ?>">
  <?php endif; ?>
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
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/index.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
  <script type="application/ld+json"<?= cspNonceAttr() ?>><?= $seriesJsonLd ?></script>
</head>
<body>
  <div class="container">
    <?php require __DIR__ . '/header.php'; ?>

    <main class="card">
      <?php if ($error !== ''): ?>
        <p class="error"><?= esc($error) ?></p>
      <?php elseif (!$episodes): ?>
        <p class="empty"><?= __('Todavía no hay capítulos publicados.') ?></p>
      <?php else: ?>
        <?php foreach ($episodes as $episode): ?>
          <article class="episode reveal">
            <?php $episodeImage = trim((string) ($episode['image_url'] ?? '')); ?>
            <?php // Usa portada del podcast cuando falta la portada del episodio. ?>
            <?php $cover = $episodeImage !== '' ? $episodeImage : $podcastImage; ?>
            <?php // Genera srcset responsive de miniaturas cuadradas y reutiliza variantes existentes. ?>
            <?php $episodeTitle = (string) ($episode['title'] !== '' && $episode['title'] !== null ? $episode['title'] : __('Sin título')); ?>
            <?php $episodeHref = resolvePodcastEpisodeHref($podcast ?? [], (string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), $episodeTitle, multipodcastEnabled($contextPdo)); ?>
            <?php $coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144,220]) : ['src' => '', 'srcset' => '']; ?>
            <?php if ($cover !== ''): ?>
              <a href="<?= esc($episodeHref) ?>" tabindex="-1" aria-hidden="true">
                <img class="cover" src="<?= esc($coverSources['src'] !== '' ? $coverSources['src'] : $cover) ?>"<?php if ($coverSources['srcset'] !== ''): ?> srcset="<?= esc($coverSources['srcset']) ?>" sizes="(max-width: 460px) 160px, (max-width: 620px) 88px, 112px"<?php endif; ?> alt="<?= esc(__('Portada del capítulo')) ?>">
              </a>
            <?php else: ?>
              <div class="cover" aria-hidden="true"></div>
            <?php endif; ?>
            <div class="episode-content">
              <?php
                $excerptSource = ($episode['short_description'] ?? '') !== ''
                    ? (string) $episode['short_description']
                    : strip_tags((string) ($episode['content'] ?? ''));
                $excerpt = firstChars($excerptSource, 200);
              ?>
              <h2><a href="<?= esc($episodeHref) ?>"><?= esc($episodeTitle) ?></a></h2>
              <p class="meta">
                <?= esc(formatPublishedDate((string) ($episode['pub_date'] ?? ''))) ?>
              </p>
              <p class="excerpt">
                <?= esc((string) $excerpt['text']) ?>
                <?php if (!empty($excerpt['truncated'])): ?>
                  <a class="read-more" href="<?= esc($episodeHref) ?>"><?= __('Leer más') ?></a>
                <?php endif; ?>
              </p>
              <?php if (!empty($episode['audio_url'])): ?>
                <audio class="player" controls preload="none" src="<?= esc((string) $episode['audio_url']) ?>" data-episode-id="<?= (int)($episode['id'] ?? 0) ?>" data-track-url="<?= esc(podcastBasePath($podcast ?? [], multipodcastEnabled($contextPdo)) . '/track') ?>">
                  <?= __('Tu navegador no soporta audio HTML5.') ?>
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
                <a class="page-link" href="index.php?page=<?= $page - 1 ?>"><?= __('Anterior') ?></a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="page-link<?= $p === $page ? ' active' : '' ?>" href="index.php?page=<?= $p ?>"><?= $p ?></a>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <a class="page-link" href="index.php?page=<?= $page + 1 ?>"><?= __('Siguiente') ?></a>
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
<?php
$cachedOutput = ob_get_contents();
if (is_string($cachedOutput)) {
    storeWebCache($dbPath, $cachedOutput);
}
ob_end_flush();
