<?php

declare(strict_types=1);

// Portada pública:
// - lista solo episodios publicados
// - soporta paginación
// - enlaza cada título a su URL amigable

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';

// Helper básico de escape HTML para salida segura.
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Fecha de publicación legible usada en las tarjetas de episodio.
function formatPublishedDate(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return date('d/m/Y H:i', $ts);
}

// Genera un extracto de texto compacto para el listado.
function firstChars(string $value, int $maxChars): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($clean === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean, 'UTF-8') <= $maxChars) {
            return $clean;
        }
        return mb_substr($clean, 0, $maxChars, 'UTF-8') . '...';
    }

    if (strlen($clean) <= $maxChars) {
        return $clean;
    }

    return substr($clean, 0, $maxChars) . '...';
}

// Construye slugs seguros para URL desde títulos de episodio.
function slugify(string $value): string
{
    $slug = trim($value);
    if ($slug === '') {
        return 'capitulo';
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($converted !== false) {
            $slug = $converted;
        }
    }

    $slug = strtolower($slug);
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'capitulo';
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
        "SELECT id, title, description, pub_date, audio_url, duration, image_url
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
if ($podcastAuthor === '') {
    $podcastAuthor = trim((string) ($podcast['author'] ?? ''));
}
$podcastImage = trim((string) ($podcast['image_url'] ?? ''));
$faviconUrl = $podcastImage;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($podcastTitle) ?></title>
  <?php if ($faviconUrl !== ''): ?>
  <link rel="icon" type="image/png" href="<?= esc($faviconUrl) ?>">
  <link rel="apple-touch-icon" href="<?= esc($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="/assets/css/index.css">
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
        <p class="desc"><?= nl2br(esc((string) $podcast['description'])) ?></p>
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
            <?php if ($cover !== ''): ?>
              <img class="cover" src="<?= esc($cover) ?>" alt="Portada del capítulo">
            <?php else: ?>
              <div class="cover" aria-hidden="true"></div>
            <?php endif; ?>
            <div class="episode-content">
              <?php $episodeTitle = (string) ($episode['title'] ?? 'Sin título'); ?>
              <h2><a href="<?= esc(buildEpisodePath((string) ($episode['pub_date'] ?? ''), $episodeTitle)) ?>"><?= esc($episodeTitle) ?></a></h2>
              <p class="meta">
                <?= esc(formatPublishedDate((string) ($episode['pub_date'] ?? ''))) ?>
              </p>
              <p><?= esc(firstChars((string) ($episode['description'] ?? ''), 200)) ?></p>
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
