<?php

declare(strict_types=1);

http_response_code(404);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
loadAppLocale($dbPath);
loadAdminTheme($dbPath);
handlePublicThemeModePreference();
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
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>" data-theme-mode="normal">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Página no encontrada') ?> – <?= esc($podcastTitle) ?></title>
  <?= publicThemeModeBootstrapScript() ?>
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico">
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <div class="container">
    <?php require __DIR__ . '/header.php'; ?>

    <main class="card error-page-card">
      <p class="error-page-code">404</p>
      <h2 class="error-page-title"><?= __('Página no encontrada') ?></h2>
      <p class="error-page-copy"><?= __('La página que estás buscando no existe o ha sido movida a otra dirección.') ?></p>
      <a href="/" class="error-cta-link"><?= __('Volver al inicio') ?></a>
    </main>

    <?php require __DIR__ . '/footer.php'; ?>
  </div>
</body>
</html>
