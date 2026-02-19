<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/seo_helpers.php';
require_once __DIR__ . '/lib/public_episode_helpers.php';

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

function escapeSqlLike(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

$podcast = null;
$podcastTitle = 'Podcast';
$podcastAuthor = '';
$podcastDescription = '';
$podcastImage = '';
$baseSeoUrl = '';

$query = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$totalEpisodes = 0;
$totalPages = 1;
$episodes = [];
$error = '';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $podcast = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: null;
    $podcastTitle = trim((string) ($podcast['title'] ?? 'Podcast'));
    $podcastAuthor = trim((string) ($podcast['owner_name'] ?? ''));
    if ($podcastAuthor === '') {
        $podcastAuthor = trim((string) ($podcast['author'] ?? ''));
    }
    $podcastDescription = trim((string) ($podcast['description'] ?? ''));
    $podcastImage = trim((string) ($podcast['image_url'] ?? ''));
    $baseSeoUrl = resolveSeoBaseUrl((string) ($podcast['link'] ?? ''));

    $configuredPerPage = (int) ($podcast['home_items_per_page'] ?? 20);
    if ($configuredPerPage >= 1) {
        $perPage = $configuredPerPage;
    }

    if ($query !== '') {
        $term = '%' . escapeSqlLike($query) . '%';

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM episodes
             WHERE status = 'published'
               AND (title LIKE :term ESCAPE '\\' OR description LIKE :term ESCAPE '\\')"
        );
        $countStmt->execute([':term' => $term]);
        $totalEpisodes = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalEpisodes / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $episodesStmt = $pdo->prepare(
            "SELECT id, title, description, link, pub_date, audio_url, duration, image_url
             FROM episodes
             WHERE status = 'published'
               AND (title LIKE :term ESCAPE '\\' OR description LIKE :term ESCAPE '\\')
             ORDER BY datetime(pub_date) DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        $episodesStmt->bindValue(':term', $term, PDO::PARAM_STR);
        $episodesStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $episodesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $episodesStmt->execute();
        $episodes = $episodesStmt->fetchAll();
    }
} catch (Throwable $e) {
    http_response_code(500);
    $error = 'No se pudo realizar la búsqueda: ' . $e->getMessage();
}

$queryParams = ['q' => $query];
if ($page > 1) {
    $queryParams['page'] = $page;
}
$canonicalPath = '/search.php' . ($query !== '' ? ('?' . http_build_query($queryParams)) : '');
$canonicalUrl = toAbsoluteSeoUrl($canonicalPath, $baseSeoUrl);
$robotsContent = 'noindex,follow';
$metaDescription = $query === ''
    ? 'Busca episodios por título o contenido.'
    : ('Resultados para "' . $query . '" en ' . $podcastTitle . '.');
$ogImage = $podcastImage !== '' ? toAbsoluteSeoUrl($podcastImage, $baseSeoUrl) : toAbsoluteSeoUrl('/favicon.ico', $baseSeoUrl);
$rssUrl = toAbsoluteSeoUrl('/feed.xml', $baseSeoUrl);

$prevUrl = null;
if ($query !== '' && $page > 1) {
    $prevParams = ['q' => $query];
    if ($page > 2) {
        $prevParams['page'] = $page - 1;
    }
    $prevUrl = toAbsoluteSeoUrl('/search.php?' . http_build_query($prevParams), $baseSeoUrl);
}
$nextUrl = null;
if ($query !== '' && $page < $totalPages) {
    $nextParams = ['q' => $query, 'page' => $page + 1];
    $nextUrl = toAbsoluteSeoUrl('/search.php?' . http_build_query($nextParams), $baseSeoUrl);
}

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
  <link rel="stylesheet" href="/assets/css/index.css">
  <link rel="stylesheet" href="/assets/css/header.css">
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
    <footer class="site-footer">
      <a href="https://github.com/educollado/EasyPodcast" target="_blank" rel="noopener noreferrer">EasyPodcast</a>
      from <a href="https://www.eduardocollado.com" target="_blank" rel="noopener noreferrer">Eduardo Collado</a>
    </footer>
  </div>
</body>
</html>
