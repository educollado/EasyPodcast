<?php

declare(strict_types=1);

/** Portada agregada cuando Multipodcast está activo y no hay podcast destacado. */
$aggregatePdo = openPodcastDatabase($dbPath);
$podcasts = $aggregatePdo->query(
    "SELECT p.id, p.title, p.description, p.author, p.owner_name, p.image_url, p.slug,
            COUNT(e.id) AS episode_count, MAX(CASE WHEN e.status = 'published' THEN e.pub_date END) AS latest_pub_date
     FROM podcast p
     LEFT JOIN episodes e ON e.podcast_id = p.id
     WHERE p.slug IS NOT NULL AND p.slug != '' AND p.include_in_summary = 1
     GROUP BY p.id
     ORDER BY latest_pub_date IS NULL ASC, latest_pub_date DESC, p.title COLLATE NOCASE ASC"
)->fetchAll();
$summarySettings = loadAppSettings($aggregatePdo);
$summaryHeroImage = $summarySettings['summary_hero_image_url'];
$summaryTitle = $summarySettings['summary_title'] !== ''
    ? $summarySettings['summary_title']
    : __('Todos nuestros podcasts, en un solo lugar.');
$summarySubtitle = $summarySettings['summary_subtitle'] !== ''
    ? $summarySettings['summary_subtitle']
    : __('Descubre todos los podcasts disponibles y sus feeds RSS.');
$baseUrl = runtimeBaseUrl();
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>" data-theme-mode="<?= esc(publicThemeMode()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($summaryTitle) ?></title>
  <meta name="description" content="<?= esc($summarySubtitle) ?>">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="<?= esc($baseUrl . '/') ?>">
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/index.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/index.css') ?>">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body><div class="container">
  <header class="multipodcast-hero<?= $summaryHeroImage !== '' ? ' has-hero' : '' ?>">
    <?php if ($summaryHeroImage !== ''): ?>
      <img class="multipodcast-hero-image" src="<?= esc($summaryHeroImage) ?>" alt="" aria-hidden="true">
    <?php endif; ?>
    <div class="multipodcast-hero-content">
      <h1><?= esc($summaryTitle) ?></h1>
      <p><?= esc($summarySubtitle) ?></p>
    </div>
    <div class="multipodcast-hero-art" aria-hidden="true">
      <span></span><span></span><span></span>
    </div>
  </header>
  <main class="card">
    <h2><?= __('Podcasts disponibles (ordenados por última actualización)') ?></h2>
    <?php if (!$podcasts): ?>
      <p class="empty"><?= __('Todavía no hay podcasts disponibles.') ?></p>
    <?php else: ?>
      <div class="podcast-directory">
      <?php foreach ($podcasts as $directoryPodcast):
          $podcastPathValue = '/' . rawurlencode((string) $directoryPodcast['slug']);
          $cover = trim((string) ($directoryPodcast['image_url'] ?? ''));
          $coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144, 220]) : ['src' => '', 'srcset' => ''];
      ?>
        <article class="episode reveal">
          <?php if ($cover !== ''): ?><a href="<?= esc($podcastPathValue . '/') ?>"><img class="cover" src="<?= esc($coverSources['src'] ?: $cover) ?>" alt="<?= esc(__('Portada del podcast')) ?>"></a><?php endif; ?>
          <div class="episode-content">
            <h3><a href="<?= esc($podcastPathValue . '/') ?>"><?= esc((string) $directoryPodcast['title']) ?></a></h3>
            <p><?= esc(firstChars(strip_tags((string) ($directoryPodcast['description'] ?? '')), 220)['text']) ?></p>
            <p class="meta"><?= __('%d capítulos', (int) $directoryPodcast['episode_count']) ?></p>
            <p><a class="rss-link" href="<?= esc($podcastPathValue . '/feed.xml') ?>"><?= __('Feed RSS') ?></a></p>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
</div></body></html>
