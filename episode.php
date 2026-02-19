<?php

declare(strict_types=1);

// Página pública de detalle de episodio resuelta por URL amigable:
// /YYYY/MM/episode-title-slug

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/seo_helpers.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';
$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
if (tryServeWebCache($dbPath, 'text/html; charset=UTF-8')) {
    exit;
}
ob_start();

// Muestra el tamaño de audio en unidades humanas.
function formatBytes($bytes): string
{
    $size = (int) $bytes;
    if ($size <= 0) {
        return '';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    $value = (float) $size;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $index === 0 ? 0 : 2, ',', '.') . ' ' . $units[$index];
}

$year = trim((string) ($_GET['year'] ?? ''));
$month = trim((string) ($_GET['month'] ?? ''));
$slug = trim((string) ($_GET['slug'] ?? ''));

$podcast = null;
$episode = null;
$error = '';

// Valida parámetros de ruta al inicio para devolver 404 consistente.
if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    $error = 'Capítulo no encontrado.';
}

if ($error === '') {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $podcast = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: null;

        // Filtra por año/mes de URL y resuelve el episodio exacto por slug.
        $stmt = $pdo->prepare(
            "SELECT *
             FROM episodes
             WHERE status = 'published'
               AND strftime('%Y', pub_date) = :year
               AND strftime('%m', pub_date) = :month
             ORDER BY datetime(pub_date) DESC, id DESC"
        );
        $stmt->execute([
            ':year' => $year,
            ':month' => $month,
        ]);
        $rows = $stmt->fetchAll();

        // Varios episodios pueden compartir mes/año. El slug resuelve el definitivo.
        foreach ($rows as $row) {
            $rowSlug = slugFromEpisodeLink((string) ($row['link'] ?? ''));
            if ($rowSlug === null) {
                $rowSlug = slugify((string) ($row['title'] ?? ''));
            }

            if ($rowSlug === $slug) {
                $episode = $row;
                break;
            }
        }

        if (!$episode) {
            http_response_code(404);
            $error = 'Capítulo no encontrado.';
        }
    } catch (Throwable $e) {
        http_response_code(500);
        $error = 'No se pudo cargar el capítulo: ' . $e->getMessage();
    }
}

$podcastTitle = trim((string) ($podcast['title'] ?? 'Podcast'));
$podcastAuthor = trim((string) ($podcast['owner_name'] ?? ''));
// Fallback de autor: owner_name -> author.
if ($podcastAuthor === '') {
    $podcastAuthor = trim((string) ($podcast['author'] ?? ''));
}
$cover = trim((string) ($episode['image_url'] ?? ''));
// Fallback de imagen del episodio a imagen del podcast.
if ($cover === '') {
    $cover = trim((string) ($podcast['image_url'] ?? ''));
}
$coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144, 220]) : ['src' => '', 'srcset' => ''];
$baseSeoUrl = resolveSeoBaseUrl((string) ($podcast['link'] ?? ''));
$canonicalPath = '/' . $year . '/' . $month . '/' . $slug;
$canonicalUrl = toAbsoluteSeoUrl($canonicalPath, $baseSeoUrl);
$robotsContent = $error !== '' ? 'noindex,follow' : 'index,follow';
$episodeTitle = (string) ($episode['title'] ?? $podcastTitle);
$pageTitle = $episode ? ($episodeTitle . ' | ' . $podcastTitle) : $podcastTitle;
$metaDescription = compactMetaText((string) ($episode['description'] ?? ''), 160);
if ($metaDescription === '') {
    $metaDescription = compactMetaText((string) ($podcast['description'] ?? ''), 160);
}
if ($metaDescription === '') {
    $metaDescription = 'Escucha este episodio en ' . $podcastTitle . '.';
}
$ogImage = $cover !== '' ? toAbsoluteSeoUrl($cover, $baseSeoUrl) : toAbsoluteSeoUrl('/favicon.ico', $baseSeoUrl);
$rssUrl = toAbsoluteSeoUrl('/feed.xml', $baseSeoUrl);
$episodeJsonLd = '{}';
if ($episode) {
    $episodeData = [
        '@context' => 'https://schema.org',
        '@type' => 'PodcastEpisode',
        'name' => $episodeTitle,
        'url' => $canonicalUrl,
        'description' => (string) ($episode['description'] ?? ''),
        'datePublished' => (string) ($episode['pub_date'] ?? ''),
        'dateModified' => (string) ($episode['updated_at'] ?? $episode['pub_date'] ?? ''),
        'partOfSeries' => [
            '@type' => 'PodcastSeries',
            'name' => $podcastTitle,
            'url' => toAbsoluteSeoUrl('/', $baseSeoUrl),
        ],
    ];
    if (!empty($episode['audio_url'])) {
        $episodeData['associatedMedia'] = [
            '@type' => 'MediaObject',
            'contentUrl' => toAbsoluteSeoUrl((string) $episode['audio_url'], $baseSeoUrl),
        ];
    }
    if ($cover !== '') {
        $episodeData['image'] = $ogImage;
    }
    $encodedEpisodeData = json_encode($episodeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($encodedEpisodeData) && $encodedEpisodeData !== '') {
        $episodeJsonLd = $encodedEpisodeData;
    }
}
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
  <script type="application/ld+json"><?= $episodeJsonLd ?></script>
</head>
<body>
  <div class="container">
    <header class="card">
      <div class="header-top">
        <h1><a href="/"><?= esc($podcastTitle) ?></a></h1>
        <div class="header-actions">
          <a class="rss-link" href="/feed.xml"><img src="/rss.png" alt="RSS"></a>
        </div>
      </div>
      <?php if ($podcastAuthor !== ''): ?>
        <p class="author"><?= esc($podcastAuthor) ?></p>
      <?php endif; ?>
    </header>
    <section class="card search-card">
      <form class="search-form" method="get" action="/search.php" role="search">
        <input type="search" name="q" placeholder="Buscar episodios" aria-label="Buscar episodios">
        <button type="submit">Buscar</button>
      </form>
    </section>

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
              <p class="desc"><?= renderTextWithLinks((string) $episode['description']) ?></p>
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
