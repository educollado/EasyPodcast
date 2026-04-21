<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/stats_downloads_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $dailyStats = getDailyStats($pdo);
    $monthlyStats = getMonthlyStats($pdo);
    $yearlyStats = getYearlyStats($pdo);
    $totalByEpisode = getTotalDownloadsByEpisode($pdo);
    $availableYears = getAvailableYears($pdo);

    $currentYear = (int) date('Y');
    $currentMonth = (int) date('n');

    // Filtrar estadísticas mensuales por año si se especifica
    $filterYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
    if ($filterYear > 0) {
        $monthlyStats = getMonthlyStats($pdo, $filterYear);
    }

    $error = '';
} catch (Throwable $e) {
    $dailyStats = [];
    $monthlyStats = [];
    $yearlyStats = [];
    $totalByEpisode = [];
    $availableYears = [];
    $error = $e->getMessage();
}

$currentAdminPage = 'stats_downloads';
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Estadísticas de descargas') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
  <style>
    .stats-tabs {
      display: flex;
      gap: .5rem;
      border-bottom: 1px solid var(--border);
      margin-bottom: 1.5rem;
    }
    .stats-tab {
      padding: .75rem 1.25rem;
      background: transparent;
      border: none;
      border-bottom: 2px solid transparent;
      cursor: pointer;
      font-size: .95rem;
      font-weight: 500;
      color: var(--muted);
      transition: all .2s ease;
    }
    .stats-tab:hover {
      color: var(--fg);
    }
    .stats-tab.active {
      color: var(--accent);
      border-bottom-color: var(--accent);
    }
    .stats-tab a {
      color: inherit;
      text-decoration: none;
    }
    .stats-panel {
      display: none;
    }
    .stats-panel.active {
      display: block;
    }
    .stats-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }
    .stats-table th,
    .stats-table td {
      padding: .75rem;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    .stats-table th {
      background: var(--bg);
      font-weight: 600;
      color: var(--muted);
      font-size: .85rem;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .stats-table tr:hover {
      background: var(--hover-bg);
    }
    .year-selector {
      margin-bottom: 1rem;
    }
    .year-selector select {
      padding: .5rem .75rem;
      border-radius: var(--radius);
      border: 1px solid var(--border);
      background: var(--bg);
      color: var(--fg);
    }
    .total-badge {
      display: inline-block;
      padding: .2rem .5rem;
      border-radius: var(--radius);
      background: var(--accent-bg);
      color: var(--accent-fg);
      font-size: .8rem;
      font-weight: 600;
    }
    .ip-tag {
      font-family: monospace;
      font-size: .8rem;
      color: var(--muted);
    }
    .empty-state {
      text-align: center;
      padding: 2rem;
      color: var(--muted);
    }
  </style>
</head>
<body>
  <?php require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Estadísticas de descargas') ?></h1>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <div class="stats-tabs">
        <button class="stats-tab active" data-tab="diario"><a><?= __('Diario (7 días)') ?></a></button>
        <button class="stats-tab" data-tab="mensual"><a><?= __('Mensual') ?></a></button>
        <button class="stats-tab" data-tab="anual"><a><?= __('Anual') ?></a></button>
        <button class="stats-tab" data-tab="resumen"><a><?= __('Resumen') ?></a></button>
      </div>

      <!-- Pestaña Diario -->
      <div id="tab-diario" class="stats-panel active">
        <p style="color: var(--muted); font-size: .9rem; margin-bottom: 1rem;">
          <?php
            $count = count($dailyStats);
            echo esc(__('Últimas %d descargas (hasta 7 días)', $count));
          ?>
        </p>
        
        <?php if (empty($dailyStats)): ?>
          <div class="empty-state"><?= __('Aún no hay datos de descargas') ?></div>
        <?php else: ?>
          <table class="stats-table">
            <thead>
              <tr>
                <th><?= __('Fecha') ?></th>
                <th><?= __('Capítulo') ?></th>
                <th><?= __('IP') ?></th>
                <th><?= __('User Agent') ?></th>
                <th><?= __('Referer') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dailyStats as $stat): ?>
                <tr>
                  <td><?= esc(formatStatsDate($stat['download_date'])) ?></td>
                  <td><?= esc($stat['episode_title']) ?></td>
                  <td class="ip-tag"><?= esc($stat['ip_address']) ?></td>
                  <td style="font-size: .8rem; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?= esc($stat['user_agent'] ?? '') ?>
                  </td>
                  <td style="font-size: .8rem; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?= esc($stat['referer'] ?? '') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Pestaña Mensual -->
      <div id="tab-mensual" class="stats-panel">
        <?php if (!empty($availableYears)): ?>
          <div class="year-selector">
            <form method="get" style="display: inline;">
              <select name="year" onchange="this.form.submit()">
                <option value=""><?= __('Todos los años') ?></option>
                <?php foreach ($availableYears as $y): ?>
                  <option value="<?= (int)$y ?>" <?= $filterYear === $y ? 'selected' : '' ?>>
                    <?= esc((string)$y) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <noscript>
                <input type="submit" value="<?= esc(__('Filtrar')) ?>">
              </noscript>
            </form>
          </div>
        <?php endif; ?>

        <?php if (empty($monthlyStats)): ?>
          <div class="empty-state"><?= __('Aún no hay datos mensuales') ?></div>
        <?php else: ?>
          <table class="stats-table">
            <thead>
              <tr>
                <th><?= __('Año/Mes') ?></th>
                <th><?= __('Capítulo') ?></th>
                <th style="text-align: right;"><?= __('Descargas') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($monthlyStats as $stat): ?>
                <tr>
                  <td><?= esc(formatMonthYear((int)$stat['anio'], (int)$stat['mes'])) ?></td>
                  <td><?= esc($stat['episode_title']) ?></td>
                  <td style="text-align: right;"><span class="total-badge"><?= (int)$stat['descargas'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Pestaña Anual -->
      <div id="tab-anual" class="stats-panel">
        <?php if (empty($yearlyStats)): ?>
          <div class="empty-state"><?= __('Aún no hay datos anuales') ?></div>
        <?php else: ?>
          <table class="stats-table">
            <thead>
              <tr>
                <th><?= __('Año') ?></th>
                <th><?= __('Capítulo') ?></th>
                <th style="text-align: right;"><?= __('Descargas') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($yearlyStats as $stat): ?>
                <tr>
                  <td><?= esc((string)$stat['anio']) ?></td>
                  <td><?= esc($stat['episode_title']) ?></td>
                  <td style="text-align: right;"><span class="total-badge"><?= (int)$stat['descargas'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Pestaña Resumen -->
      <div id="tab-resumen" class="stats-panel">
        <h3 style="margin-top: 0;"><?= __('Total de descargas por capítulo') ?></h3>
        
        <?php if (empty($totalByEpisode)): ?>
          <div class="empty-state"><?= __('Aún no hay descargas registradas') ?></div>
        <?php else: ?>
          <table class="stats-table">
            <thead>
              <tr>
                <th><?= __('Capítulo') ?></th>
                <th style="text-align: right;"><?= __('Total descargas') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($totalByEpisode as $stat): ?>
                <?php if ((int)$stat['total_downloads'] > 0): ?>
                  <tr>
                    <td><?= esc($stat['episode_title']) ?></td>
                    <td style="text-align: right;"><span class="total-badge"><?= (int)$stat['total_downloads'] ?></span></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <script>
    // Pestañas
    document.querySelectorAll('.stats-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.stats-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.stats-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
      });
    });
  </script>
</body>
</html>
