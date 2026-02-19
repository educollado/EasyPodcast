<?php

declare(strict_types=1);

// Portada pública:
// - lista solo episodios publicados
// - soporta paginación
// - enlaza cada título a su URL amigable

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/cache_service.php';
$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
if (tryServeWebCache($dbPath, 'text/html; charset=UTF-8')) {
    exit;
}
ob_start();

// Genera un extracto de texto compacto y marca si hubo recorte.
function firstChars(string $value, int $maxChars): array
{
    $clean = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($clean === '') {
        return ['text' => '', 'truncated' => false];
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean, 'UTF-8') <= $maxChars) {
            return ['text' => $clean, 'truncated' => false];
        }
        return ['text' => rtrim(mb_substr($clean, 0, $maxChars, 'UTF-8')), 'truncated' => true];
    }

    if (strlen($clean) <= $maxChars) {
        return ['text' => $clean, 'truncated' => false];
    }

    return ['text' => rtrim(substr($clean, 0, $maxChars)), 'truncated' => true];
}

// Ruta amigable usada por .htaccess y episode.php.
function buildEpisodePath(string $pubDate, string $title): string
{
    $ts = strtotime($pubDate);
    if ($ts === false) {
        $ts = time();
    }
    $year = date('Y', $ts);
    $month = date('m', $ts);
    return '/' . $year . '/' . $month . '/' . slugify($title);
}

// Usa el enlace guardado si existe; si no, construye uno desde fecha+título.
function resolveEpisodeHref(?string $storedLink, string $pubDate, string $title): string
{
    $link = trim((string) $storedLink);
    if ($link !== '') {
        return $link;
    }

    return buildEpisodePath($pubDate, $title);
}

// Base absoluta para etiquetas SEO (canonical, OG, JSON-LD).
function resolveSeoBaseUrl(?string $podcastLink): string
{
    $raw = trim((string) $podcastLink);
    if ($raw !== '') {
        $parts = parse_url($raw);
        if (is_array($parts) && !empty($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            $host = (string) $parts['host'];
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            return $scheme . '://' . $host . $port;
        }
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

// Convierte rutas relativas en URLs absolutas para OG/JSON-LD.
function toAbsoluteSeoUrl(string $value, string $baseUrl): string
{
    $raw = trim($value);
    if ($raw === '') {
        return rtrim($baseUrl, '/');
    }

    $parts = parse_url($raw);
    if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        return $raw;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($raw, '/');
}

// Limpia y recorta texto para meta description.
function compactMetaText(string $value, int $maxChars = 160): string
{
    $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    if ($clean === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean, 'UTF-8') <= $maxChars) {
            return $clean;
        }
        return rtrim(mb_substr($clean, 0, $maxChars, 'UTF-8')) . '...';
    }

    if (strlen($clean) <= $maxChars) {
        return $clean;
    }

    return rtrim(substr($clean, 0, $maxChars)) . '...';
}

$podcast = null;
$episodes = [];
$error = '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$totalEpisodes = 0;
$totalPages = 1;

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // La app está diseñada alrededor de una única fila de podcast.
    $podcast = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: null;

    // Calcula paginación total antes de consultar la página actual.
    $totalEpisodes = (int) $pdo
        ->query("SELECT COUNT(*) FROM episodes WHERE status = 'published'")
        ->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEpisodes / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    // Recupera solo episodios de la página actual (solo publicados).
    $episodesStmt = $pdo->prepare(
        "SELECT id, title, description, link, pub_date, audio_url, duration, image_url
         FROM episodes
         WHERE status = 'published'
         ORDER BY datetime(pub_date) DESC, id DESC
         LIMIT :limit OFFSET :offset"
    );
    $episodesStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $episodesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $episodesStmt->execute();
    $episodes = $episodesStmt->fetchAll();
} catch (Throwable $e) {
    $error = 'No se pudo cargar la portada: ' . $e->getMessage();
}

$podcastTitle = trim((string) ($podcast['title'] ?? 'Podcast'));
$podcastAuthor = trim((string) ($podcast['owner_name'] ?? ''));
// Fallback de autor: owner_name -> author.
if ($podcastAuthor === '') {
    $podcastAuthor = trim((string) ($podcast['author'] ?? ''));
}
$podcastImage = trim((string) ($podcast['image_url'] ?? ''));
$baseSeoUrl = resolveSeoBaseUrl((string) ($podcast['link'] ?? ''));
$canonicalPath = $page > 1 ? '/?page=' . $page : '/';
$canonicalUrl = toAbsoluteSeoUrl($canonicalPath, $baseSeoUrl);
$robotsContent = $error !== '' ? 'noindex,follow' : ($page > 1 ? 'noindex,follow' : 'index,follow');
$prevUrl = null;
if ($page > 1) {
    $prevPath = $page === 2 ? '/' : '/?page=' . ($page - 1);
    $prevUrl = toAbsoluteSeoUrl($prevPath, $baseSeoUrl);
}
$nextUrl = null;
if ($page < $totalPages) {
    $nextUrl = toAbsoluteSeoUrl('/?page=' . ($page + 1), $baseSeoUrl);
}
$metaDescription = compactMetaText((string) ($podcast['description'] ?? ''), 160);
if ($metaDescription === '') {
    $metaDescription = 'Podcast en EasyPodcast: episodios, reproductor y feed RSS.';
}
$ogImage = $podcastImage !== '' ? toAbsoluteSeoUrl($podcastImage, $baseSeoUrl) : toAbsoluteSeoUrl('/favicon.ico', $baseSeoUrl);
$rssUrl = toAbsoluteSeoUrl('/feed.xml', $baseSeoUrl);
$seriesData = [
    '@context' => 'https://schema.org',
    '@type' => 'PodcastSeries',
    'name' => $podcastTitle,
    'url' => toAbsoluteSeoUrl('/', $baseSeoUrl),
    'description' => (string) ($podcast['description'] ?? ''),
    'inLanguage' => (string) ($podcast['language'] ?? 'es-ES'),
];
if ($podcastAuthor !== '') {
    $seriesData['author'] = [
        '@type' => 'Person',
        'name' => $podcastAuthor,
    ];
}
if ($podcastImage !== '') {
    $seriesData['image'] = $ogImage;
}
$seriesJsonLd = json_encode($seriesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($seriesJsonLd) || $seriesJsonLd === '') {
    $seriesJsonLd = '{}';
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
  <link rel="stylesheet" href="/assets/css/index.css">
  <script type="application/ld+json"><?= $seriesJsonLd ?></script>
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
      <?php if (!empty($podcast['description'])): ?>
        <p class="desc"><?= renderTextWithLinks((string) $podcast['description']) ?></p>
      <?php endif; ?>
    </header>

    <main class="card">
      <?php if ($error !== ''): ?>
        <p class="error"><?= esc($error) ?></p>
      <?php elseif (!$episodes): ?>
        <p class="empty">Todavía no hay capítulos publicados.</p>
      <?php else: ?>
        <?php foreach ($episodes as $episode): ?>
          <article class="episode">
            <?php $episodeImage = trim((string) ($episode['image_url'] ?? '')); ?>
            <?php // Usa portada del podcast cuando falta la portada del episodio. ?>
            <?php $cover = $episodeImage !== '' ? $episodeImage : $podcastImage; ?>
            <?php // Genera srcset responsive de miniaturas cuadradas y reutiliza variantes existentes. ?>
            <?php $coverSources = $cover !== '' ? buildResponsiveSquareImageSources($cover, [144,220]) : ['src' => '', 'srcset' => '']; ?>
            <?php if ($cover !== ''): ?>
              <img class="cover" src="<?= esc($coverSources['src'] !== '' ? $coverSources['src'] : $cover) ?>"<?php if ($coverSources['srcset'] !== ''): ?> srcset="<?= esc($coverSources['srcset']) ?>" sizes="(max-width: 460px) 180px, (max-width: 620px) 108px, 144px"<?php endif; ?> alt="Portada del capítulo">
            <?php else: ?>
              <div class="cover" aria-hidden="true"></div>
            <?php endif; ?>
            <div class="episode-content">
              <?php $episodeTitle = (string) ($episode['title'] ?? 'Sin título'); ?>
              <?php $episodeHref = resolveEpisodeHref((string) ($episode['link'] ?? ''), (string) ($episode['pub_date'] ?? ''), $episodeTitle); ?>
              <?php $excerpt = firstChars((string) ($episode['description'] ?? ''), 200); ?>
              <h2><a href="<?= esc($episodeHref) ?>"><?= esc($episodeTitle) ?></a></h2>
              <p class="meta">
                <?= esc(formatPublishedDate((string) ($episode['pub_date'] ?? ''))) ?>
              </p>
              <p class="excerpt">
                <?= esc((string) $excerpt['text']) ?>
                <?php if (!empty($excerpt['truncated'])): ?>
                  <a class="read-more" href="<?= esc($episodeHref) ?>">[...]</a>
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
