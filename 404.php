<?php

declare(strict_types=1);

http_response_code(404);

require_once __DIR__ . '/lib/view_helpers.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
$podcast = null;
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $podcast = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch() ?: null;
} catch (Throwable $e) {
    // Si la BD no responde el header usa los valores por defecto.
}

$podcastTitle       = (string) ($podcast['title']       ?? 'Podcast');
$podcastAuthor      = (string) ($podcast['author']      ?? '');
$podcastDescription = (string) ($podcast['description'] ?? '');
$podcastImage       = (string) ($podcast['image_url']   ?? '');
$searchQuery        = '';

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Página no encontrada – <?= esc($podcastTitle) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico">
  <script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/dark.css">
</head>
<body>
  <div class="container">
    <?php require __DIR__ . '/header.php'; ?>

    <main class="card" style="text-align:center;padding:3.5rem 2rem;">
      <p style="font-family:var(--font-display);font-size:clamp(4rem,15vw,7rem);font-weight:700;line-height:1;margin:0;color:var(--accent);letter-spacing:-0.03em;">404</p>
      <h2 style="font-family:var(--font-display);font-size:clamp(1.2rem,4vw,1.6rem);margin:.75rem 0 .6rem;">Página no encontrada</h2>
      <p style="color:var(--muted);margin:0 auto;max-width:38ch;">La página que estás buscando no existe o ha sido movida a otra dirección.</p>
      <a href="/" style="display:inline-block;margin-top:2rem;padding:.5rem 1.4rem;border-radius:20px;background:var(--accent);color:#fff;font-weight:600;font-size:.9rem;text-decoration:none;transition:background .15s;" onmouseover="this.style.background='var(--accent-dark)'" onmouseout="this.style.background='var(--accent)'">Volver al inicio</a>
    </main>

    <?php require __DIR__ . '/footer.php'; ?>
  </div>
</body>
</html>
