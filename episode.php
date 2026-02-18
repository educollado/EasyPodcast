<?php

declare(strict_types=1);

// Página pública de detalle de episodio resuelta por URL amigable:
// /YYYY/MM/episode-title-slug

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);

// Extrae el slug desde una URL guardada tipo /YYYY/MM/slug.
function slugFromEpisodeLink(?string $link): ?string
{
    $raw = trim((string) $link);
    if ($raw === '') {
        return null;
    }

    $path = (string) parse_url($raw, PHP_URL_PATH);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^/[0-9]{4}/[0-9]{2}/([a-z0-9-]+)/?$#', $path, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

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
$faviconUrl = trim((string) ($podcast['image_url'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($episode ? (string) ($episode['title'] ?? $podcastTitle) : $podcastTitle) ?></title>
  <?php if ($faviconUrl !== ''): ?>
  <link rel="icon" type="image/png" href="<?= esc($faviconUrl) ?>">
  <link rel="apple-touch-icon" href="<?= esc($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="/assets/css/episode.css">
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

    <main class="card">
      <?php if ($error !== ''): ?>
        <p class="error"><?= esc($error) ?></p>
      <?php else: ?>
        <article class="detail">
          <?php if ($cover !== ''): ?>
            <img class="cover" src="<?= esc($cover) ?>" alt="Portada del capítulo">
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
