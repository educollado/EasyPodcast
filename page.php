<?php

declare(strict_types=1);

// Página pública de páginas estáticas, resuelta por URL amigable:
// /slug y /padre/hijo

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/page_helpers.php';
require_once __DIR__ . '/lib/social_handler.php';
require_once __DIR__ . '/lib/seo_helpers.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

// Detecta sesión de admin para previsualización de borradores.
session_start();
$isAdminPreview = isset($_SESSION['admin_user']);

// No sirve caché si hay sesión de admin.
if (!$isAdminPreview && tryServeWebCache($dbPath, 'text/html; charset=UTF-8')) {
    exit;
}
ob_start();

$fullPath = trim((string) ($_GET['full_path'] ?? ''));
// Sanitiza: solo letras minúsculas, números, guiones y una barra opcional.
if (!preg_match('/^[a-z0-9-]+(?:\/[a-z0-9-]+)?$/', $fullPath)) {
    $fullPath = '';
}

$data = loadPageData($dbPath, $fullPath, $isAdminPreview);
extract($data);  // page, parent, children, httpStatus, error, podcast

$isDraft = $page !== null && ($page['status'] ?? '') === 'draft';

// Si la página no existe (o error), mostrar el 404 personalizado y salir.
if ($httpStatus !== 200) {
    ob_end_clean();
    require __DIR__ . '/404.php';
    exit;
}

$podcast    = $podcast ?? [];
$seo        = buildPageSeoData($podcast, $page, $error);
extract($seo);

$_social = getSocialLinks($dbPath);
$_fediverseCreator = mastodonUrlToFediverseHandle((string) ($_social['mastodon'] ?? ''));  // podcastTitle, podcastAuthor, podcastDescription, podcastImage,
                // baseSeoUrl, canonicalUrl, robotsContent, pageTitle,
                // metaDescription, ogImage, rssUrl

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
  <?php // Aplica el tema guardado ANTES de cargar el CSS para evitar parpadeo (FOUC). ?>
  <script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/episode.css">
  <link rel="stylesheet" href="/assets/css/dark.css">
</head>
<body>
  <div class="container">
    <?php require __DIR__ . '/header.php'; ?>

    <?php if ($isDraft): ?>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:var(--radius);padding:.75rem 1.25rem;display:flex;align-items:center;gap:.6rem;font-size:.9rem;font-weight:600;color:#664d03;">
      <span aria-hidden="true">✏️</span> Borrador — Esta página no está publicada y solo es visible para administradores.
    </div>
    <?php endif; ?>

    <main class="card">
        <article class="page-article">
          <div>
            <?php if ($parent !== null): ?>
              <nav class="breadcrumb" aria-label="Ruta">
                <a href="/<?= esc((string) $parent['full_path']) ?>"><?= esc((string) $parent['title']) ?></a>
                <span aria-hidden="true"> › </span>
                <span><?= esc((string) ($page['title'] ?? '')) ?></span>
              </nav>
            <?php endif; ?>

            <h1><?= esc((string) ($page['title'] ?? 'Sin título')) ?></h1>

            <?php if (!empty($page['content'])): ?>
              <div class="desc"><?= renderMarkdown((string) $page['content']) ?></div>
            <?php endif; ?>

            <?php if ($children): ?>
              <nav class="page-children" aria-label="Subpáginas">
                <ul>
                  <?php foreach ($children as $child): ?>
                    <li><a href="/<?= esc((string) $child['full_path']) ?>"><?= esc((string) $child['title']) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </nav>
            <?php endif; ?>
          </div>
        </article>
    </main>

    <?php require __DIR__ . '/footer.php'; ?>
  </div>
</body>
</html>
<?php
$cachedOutput = ob_get_contents();
// No cachear borradores ni errores.
if (!$isDraft && $error === '' && is_string($cachedOutput)) {
    storeWebCache($dbPath, $cachedOutput);
}
ob_end_flush();
