<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/stats_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadStatsData($dbPath);
extract($data); // published, drafts, total, lastTitle, lastPubDate, audioSizeBytes, error
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Estadísticas</title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <style>
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap: 1rem;
      margin-top: 1.25rem;
    }
    .stat-card {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 1.1rem 1.25rem;
      display: flex;
      flex-direction: column;
      gap: .3rem;
    }
    .stat-label {
      font-size: .8rem;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      line-height: 1.1;
      color: var(--fg);
    }
    .stat-value.accent { color: var(--accent); }
    .stat-sub {
      font-size: .85rem;
      color: var(--muted);
      margin-top: .1rem;
    }
    .stat-wide {
      grid-column: 1 / -1;
    }
  </style>
</head>
<body>
  <?php $currentAdminPage = 'stats'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1>Estadísticas</h1>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <div class="stats-grid">

        <div class="stat-card">
          <span class="stat-label">Publicados</span>
          <span class="stat-value accent"><?= $published ?></span>
          <span class="stat-sub">episodios en el feed</span>
        </div>

        <div class="stat-card">
          <span class="stat-label">Borradores</span>
          <span class="stat-value"><?= $drafts ?></span>
          <span class="stat-sub">sin publicar</span>
        </div>

        <div class="stat-card">
          <span class="stat-label">Total</span>
          <span class="stat-value"><?= $total ?></span>
          <span class="stat-sub">episodios en la BD</span>
        </div>

        <div class="stat-card">
          <span class="stat-label">Tamaño de audios</span>
          <span class="stat-value"><?= esc(formatBytes($audioSizeBytes)) ?></span>
          <span class="stat-sub">según metadatos de BD</span>
        </div>

        <?php if ($lastTitle !== ''): ?>
        <div class="stat-card stat-wide">
          <span class="stat-label">Último publicado</span>
          <span class="stat-value" style="font-size:1.1rem; font-weight:600;"><?= esc($lastTitle) ?></span>
          <?php if ($lastPubDate !== ''): ?>
            <span class="stat-sub"><?= esc($lastPubDate) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>
</body>
</html>
