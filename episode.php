<?php

declare(strict_types=1);

// Página pública de detalle de episodio resuelta por URL amigable:
// /YYYY/MM/episode-title-slug

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/seo_helpers.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';
require_once __DIR__ . '/lib/episode_query.php';
require_once __DIR__ . '/lib/episode_seo.php';
require_once __DIR__ . '/lib/social_handler.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

// Detecta sesión de admin para permitir previsualización de borradores.
startSecureSession();
$isAdminPreview = isset($_SESSION['admin_user']);

// No sirve caché si hay sesión de admin (podría haber drafts o cambios recientes).
if (!$isAdminPreview && tryServeWebCache($dbPath, 'text/html; charset=UTF-8')) {
    exit;
}
ob_start();

$year  = trim((string) ($_GET['year'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
$slug  = trim((string) ($_GET['slug'] ?? ''));

$data = loadEpisodeData($dbPath, $year, $month, $slug, $isAdminPreview);
extract($data);  // podcast, episode, error, httpStatus

if ($httpStatus === 404) {
    ob_end_clean();
    require __DIR__ . '/404.php';
    exit;
}

$isDraft = $episode !== null && ($episode['status'] ?? '') === 'draft';

if ($httpStatus !== 200) {
    http_response_code($httpStatus);
}

$seo = buildEpisodeSeoData($podcast, $episode, $year, $month, $slug, $error);
extract($seo);   // podcastTitle, podcastAuthor, podcastDescription, cover,
                 // baseSeoUrl, canonicalUrl, robotsContent, episodeTitle, pageTitle,
                 // metaDescription, ogImage, rssUrl, episodeJsonLd

$coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144, 220]) : ['src' => '', 'srcset' => ''];

$_social = getSocialLinks($dbPath);
$_fediverseCreator = mastodonUrlToFediverseHandle((string) ($_social['mastodon'] ?? ''));

if ($error !== '') {
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>" data-theme-mode="normal">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($pageTitle) ?></title>
  <?= publicThemeModeBootstrapScript() ?>
  <meta name="robots" content="<?= esc($robotsContent) ?>">
  <meta name="description" content="<?= esc($metaDescription) ?>">
  <?php if ($_fediverseCreator !== ''): ?>
  <meta name="fediverse:creator" content="<?= esc($_fediverseCreator) ?>">
  <?php endif; ?>
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/episode.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
  <script type="application/ld+json"<?= cspNonceAttr() ?>><?= $episodeJsonLd ?></script>
</head>
<body>
  <div class="container">
    <?php require __DIR__ . '/header.php'; ?>

    <?php if ($isDraft): ?>
    <div class="draft-banner">
      <span aria-hidden="true">✏️</span> <?= __('Borrador — Esta página no está publicada y solo es visible para administradores.') ?>
    </div>
    <?php endif; ?>

    <main class="card">
      <?php if ($error !== ''): ?>
        <p class="error"><?= esc($error) ?></p>
      <?php else: ?>
        <article class="detail">
          <?php if ($cover !== ''): ?>
            <img class="cover" src="<?= esc($coverSources['src'] !== '' ? $coverSources['src'] : $cover) ?>"<?php if ($coverSources['srcset'] !== ''): ?> srcset="<?= esc($coverSources['srcset']) ?>" sizes="(max-width: 460px) 200px, (max-width: 760px) 160px, 200px"<?php endif; ?> alt="<?= esc(__('Portada del capítulo')) ?>">
          <?php else: ?>
            <div class="cover" aria-hidden="true"></div>
          <?php endif; ?>
          <div>
            <h1><?= esc(($episode['title'] ?? '') !== '' ? (string) $episode['title'] : __('Sin título')) ?></h1>
            <p class="meta"><?= esc(formatPublishedDate((string) ($episode['pub_date'] ?? ''))) ?></p>
            <?php if (!empty($episode['audio_url'])): ?>
              <audio class="player" controls preload="none" src="<?= esc((string) $episode['audio_url']) ?>" data-episode-id="<?= (int) ($episode['id'] ?? 0) ?>">
                <?= __('Tu navegador no soporta audio HTML5.') ?>
              </audio>
              <?php // Metadatos de audio: enlace de descarga con duración y tamaño entre paréntesis. ?>
              <?php
                $duration    = trim((string) ($episode['duration'] ?? ''));
                $readableSize = formatBytes($episode['audio_size_bytes'] ?? 0);
                // Construye la parte entre paréntesis solo con los datos disponibles.
                $metaParts = [];
                if ($duration !== '')    $metaParts[] = __('Duración: %s', $duration);
                if ($readableSize !== '') $metaParts[] = $readableSize;
                $metaParens = $metaParts ? ' (' . implode(' — ', $metaParts) . ')' : '';
              ?>
              <p class="audio-meta">
                <a class="download" href="/track.php?episode_id=<?= (int)($episode['id'] ?? 0) ?>&action=download" download><?= __('Descargar') ?></a><?= esc($metaParens) ?>
              </p>
            <?php endif; ?>
            <?php if (!empty($episode['content'])): ?>
              <div class="desc"><?= sanitizeRichHtml((string) $episode['content']) ?></div>
            <?php endif; ?>
          </div>
        </article>
      <?php endif; ?>
    </main>
    <?php require __DIR__ . '/footer.php'; ?>
  </div>
</body>
</html>
<?php
$cachedOutput = ob_get_contents();
// No cachear borradores (podrían verse en caché por usuarios no autenticados).
// Los admins siguen viendo siempre contenido fresco (tryServeWebCache los salta).
if (!$isDraft && is_string($cachedOutput)) {
    storeWebCache($dbPath, $cachedOutput);
}
ob_end_flush();
