<?php

declare(strict_types=1);

// Página pública de detalle de episodio resuelta por URL amigable:
// /YYYY/MM/episode-title-slug

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/seo_helpers.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';
require_once __DIR__ . '/lib/episode_query.php';
require_once __DIR__ . '/lib/episode_seo.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
if (tryServeWebCache($dbPath, 'text/html; charset=UTF-8')) {
    exit;
}
ob_start();

$year  = trim((string) ($_GET['year'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
$slug  = trim((string) ($_GET['slug'] ?? ''));

$data = loadEpisodeData($dbPath, $year, $month, $slug);
extract($data);  // podcast, episode, error, httpStatus

if ($httpStatus !== 200) {
    http_response_code($httpStatus);
}

$seo = buildEpisodeSeoData($podcast, $episode, $year, $month, $slug, $error);
extract($seo);   // podcastTitle, podcastAuthor, podcastDescription, cover,
                 // baseSeoUrl, canonicalUrl, robotsContent, episodeTitle, pageTitle,
                 // metaDescription, ogImage, rssUrl, episodeJsonLd

$coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144, 220]) : ['src' => '', 'srcset' => ''];

if ($error !== '') {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($pageTitle) ?></title>
  <meta name="robots" content="<?= esc($robotsContent) ?>">
  <meta name="description" content="<?= esc($metaDescription) ?>">
  <link rel="canonical" href="<?= esc($canonicalUrl) ?>">
  <link rel="alternate" type="application/rss+xml" title="<?= esc($podcastTitle) ?> RSS" href="<?= esc($rssUrl) ?>">
  <meta property="og:type" content="article">
  <meta property="og:site_name" content="<?= esc($podcastTitle) ?>">
  <meta property="og:title" content="<?= esc($pageTitle) ?>">
  <meta property="og:description" content="<?= esc($metaDescription) ?>">
  <meta property="og:url" content="<?= esc($canonicalUrl) ?>">
  <meta property="og:image" content="<?= esc($ogImage) ?>">
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico">
  <link rel="stylesheet" href="/assets/css/episode.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <script type="application/ld+json"><?= $episodeJsonLd ?></script>
</head>
<body>
  <div class="container">
    <?php require __DIR__ . '/header.php'; ?>

    <main class="card">
      <?php if ($error !== ''): ?>
        <p class="error"><?= esc($error) ?></p>
      <?php else: ?>
        <article class="detail">
          <?php if ($cover !== ''): ?>
            <img class="cover" src="<?= esc($coverSources['src'] !== '' ? $coverSources['src'] : $cover) ?>"<?php if ($coverSources['srcset'] !== ''): ?> srcset="<?= esc($coverSources['srcset']) ?>" sizes="(max-width: 460px) 220px, (max-width: 760px) 160px, 220px"<?php endif; ?> alt="Portada del capítulo">
          <?php else: ?>
            <div class="cover" aria-hidden="true"></div>
          <?php endif; ?>
          <div>
            <h1><?= esc((string) ($episode['title'] ?? 'Sin título')) ?></h1>
            <p class="meta"><?= esc(formatPublishedDate((string) ($episode['pub_date'] ?? ''))) ?></p>
            <?php if (!empty($episode['description'])): ?>
              <div class="desc"><?= renderMarkdown((string) $episode['description']) ?></div>
            <?php endif; ?>
            <?php if (!empty($episode['audio_url'])): ?>
              <p class="audio-meta">
                <?php if (!empty($episode['duration'])): ?>
                  Duración: <?= esc((string) $episode['duration']) ?>
                <?php endif; ?>
                <?php $readableSize = formatBytes($episode['audio_size_bytes'] ?? 0); ?>
                <?php if ($readableSize !== ''): ?>
                  <?php if (!empty($episode['duration'])): ?> · <?php endif; ?>
                  Tamaño: <?= esc($readableSize) ?>
                <?php endif; ?>
                <a class="download" href="<?= esc((string) $episode['audio_url']) ?>" download>Descargar</a>
              </p>
              <audio class="player" controls preload="none" src="<?= esc((string) $episode['audio_url']) ?>">
                Tu navegador no soporta audio HTML5.
              </audio>
            <?php endif; ?>
          </div>
        </article>
      <?php endif; ?>
    </main>
    <footer class="site-footer">
      <a href="https://github.com/educollado/EasyPodcast" target="_blank" rel="noopener noreferrer">EasyPodcast</a>
      from <a href="https://www.eduardocollado.com" target="_blank" rel="noopener noreferrer">Eduardo Collado</a>
    </footer>
  </div>
</body>
</html>
<?php
$cachedOutput = ob_get_contents();
if (is_string($cachedOutput)) {
    storeWebCache($dbPath, $cachedOutput);
}
ob_end_flush();
