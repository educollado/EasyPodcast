<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/stats_handler.php';
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

$data = loadStatsData($dbPath);
extract($data); // published, drafts, total, lastTitle, lastPubDate, audioSizeBytes, cacheEnabled, cacheFiles, cacheSizeBytes, error

// Datos de descargas
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $dailyStats = getDailyStats($pdo);
    $monthlyStats = getMonthlyStats($pdo);
    $yearlyStats = getYearlyStats($pdo);
    $totalByEpisode = getTotalDownloadsByEpisode($pdo);
    $availableYears = getAvailableYears($pdo);

    $downloadsError = '';
} catch (Throwable $e) {
    $dailyStats = [];
    $monthlyStats = [];
    $yearlyStats = [];
    $totalByEpisode = [];
    $availableYears = [];
    $downloadsError = $e->getMessage();
}

// Filtrar estadísticas mensuales por año si se especifica
$filterYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
if ($filterYear > 0) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $monthlyStats = getMonthlyStats($pdo, $filterYear);
    } catch (Throwable $e) {
        $monthlyStats = [];
    }
}
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Estadísticas') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
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
      <h1><?= __('Estadísticas') ?></h1>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <div class="stats-grid">

        <div class="stat-card">
          <span class="stat-label"><?= __('Publicados') ?></span>
          <span class="stat-value accent"><?= $published ?></span>
          <span class="stat-sub"><?= __('episodios en el feed') ?></span>
        </div>

        <div class="stat-card">
          <span class="stat-label"><?= __('Borradores') ?></span>
          <span class="stat-value"><?= $drafts ?></span>
          <span class="stat-sub"><?= __('sin publicar') ?></span>
        </div>

        <div class="stat-card">
          <span class="stat-label"><?= __('Total') ?></span>
          <span class="stat-value"><?= $total ?></span>
          <span class="stat-sub"><?= __('episodios en la BD') ?></span>
        </div>

        <div class="stat-card">
          <span class="stat-label"><?= __('Tamaño de audios') ?></span>
          <span class="stat-value"><?= esc(statsFormatBytes($audioSizeBytes)) ?></span>
          <span class="stat-sub"><?= __('según metadatos de BD') ?></span>
        </div>

        <?php if ($lastTitle !== ''): ?>
        <div class="stat-card stat-wide">
          <span class="stat-label"><?= __('Último publicado') ?></span>
          <span class="stat-value" style="font-size:1.1rem; font-weight:600;"><?= esc($lastTitle) ?></span>
          <?php if ($lastPubDate !== ''): ?>
            <span class="stat-sub"><?= esc($lastPubDate) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>

      <h2 style="margin-top:2rem; font-size:1.05rem; color:var(--muted); text-transform:uppercase; letter-spacing:.06em;">Caché</h2>
      <div class="stats-grid">

        <div class="stat-card">
          <span class="stat-label"><?= __('Estado') ?></span>
          <span class="stat-value" style="font-size:1.2rem;">
            <?php if ($cacheEnabled): ?>
              <span style="color:#059669;"><?= __('Activa') ?></span>
            <?php else: ?>
              <span style="color:var(--muted);"><?= __('Inactiva') ?></span>
            <?php endif; ?>
          </span>
          <span class="stat-sub"><a href="cache_management.php" style="color:var(--accent);"><?= __('Gestionar caché') ?></a></span>
        </div>

        <div class="stat-card">
          <span class="stat-label"><?= __('Páginas en caché') ?></span>
          <span class="stat-value"><?= $cacheFiles ?></span>
          <span class="stat-sub"><?= __('ficheros .cache') ?></span>
        </div>

        <div class="stat-card">
          <span class="stat-label"><?= __('Tamaño de caché') ?></span>
          <span class="stat-value"><?= esc(statsFormatBytes($cacheSizeBytes)) ?></span>
          <span class="stat-sub"><?= __('HTML cacheado') ?></span>
        </div>

      </div>

      <h2 style="margin-top:2rem; font-size:1.05rem; color:var(--muted); text-transform:uppercase; letter-spacing:.06em;"><?= __('Estadísticas de descargas') ?></h2>
      
      <?php if ($downloadsError !== ''): ?>
        <div class="error"><?= esc($downloadsError) ?></div>
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
            echo esc(__('Últimas %d descargas y reproducciones (hasta 7 días)', $count));
          ?>
        </p>
        
        <?php if (empty($dailyStats)): ?>
          <div class="empty-state"><?= __('Aún no hay datos de descargas o reproducciones') ?></div>
        <?php else: ?>
          <table class="stats-table">
            <thead>
              <tr>
                <th data-sort="date"><?= __('Fecha') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="title"><?= __('Capítulo') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="type"><?= __('Tipo') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="ip"><?= __('IP') ?> <span class="sort-icon">↕</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dailyStats as $stat): ?>
                <tr>
                  <td><?= esc(formatStatsDate($stat['download_date'])) ?></td>
                  <td><?= esc($stat['episode_title']) ?></td>
                  <td>
                    <span style="font-size:.8rem; padding:.2rem .4rem; border-radius:var(--radius); background:var(--bg); color:var(--fg);">
                      <?php
                        $type = isset($stat['action_type']) && $stat['action_type'] === 'play' ? 'play' : 'download';
                        echo esc($type === 'play' ? __('Reproducción') : __('Descarga'));
                      ?>
                    </span>
                  </td>
                  <td class="ip-tag"><?= esc($stat['ip_address']) ?></td>
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
              <input type="hidden" name="tab" value="mensual">
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
                <th data-sort="period"><?= __('Año/Mes') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="title"><?= __('Capítulo') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="count" style="text-align: right;"><?= __('Descargas') ?> <span class="sort-icon">↕</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($monthlyStats as $stat): ?>
                <tr>
                  <td data-sort-value="<?= (int)$stat['anio'] * 12 + (int)$stat['mes'] ?>"><?= esc(formatMonthYear((int)$stat['anio'], (int)$stat['mes'])) ?></td>
                  <td><?= esc($stat['episode_title']) ?></td>
                  <td data-sort-value="<?= (int)$stat['descargas'] ?>" style="text-align: right;"><span class="total-badge"><?= (int)$stat['descargas'] ?></span></td>
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
                <th data-sort="year"><?= __('Año') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="title"><?= __('Capítulo') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="count" style="text-align: right;"><?= __('Descargas') ?> <span class="sort-icon">↕</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($yearlyStats as $stat): ?>
                <tr>
                  <td data-sort-value="<?= (int)$stat['anio'] ?>"><?= esc((string)$stat['anio']) ?></td>
                  <td><?= esc($stat['episode_title']) ?></td>
                  <td data-sort-value="<?= (int)$stat['descargas'] ?>" style="text-align: right;"><span class="total-badge"><?= (int)$stat['descargas'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Pestaña Resumen -->
      <div id="tab-resumen" class="stats-panel">
        <h3 style="margin-top: 0;"><?= __('Total de descargas y reproducciones por capítulo') ?></h3>
        
        <?php if (empty($totalByEpisode)): ?>
          <div class="empty-state"><?= __('Aún no hay descargas ni reproducciones registradas') ?></div>
        <?php else: ?>
          <table class="stats-table">
            <thead>
              <tr>
                <th data-sort="title"><?= __('Capítulo') ?> <span class="sort-icon">↕</span></th>
                <th data-sort="total" style="text-align: right;"><?= __('Total') ?> <span class="sort-icon">↕</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($totalByEpisode as $stat): ?>
                <?php if ((int)$stat['total_downloads'] > 0): ?>
                  <tr>
                    <td><?= esc($stat['episode_title']) ?></td>
                    <td data-sort-value="<?= (int)$stat['total_downloads'] ?>" style="text-align: right;"><span class="total-badge"><?= (int)$stat['total_downloads'] ?></span></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <style>
    .stats-tabs {
      display: flex;
      gap: .5rem;
      border-bottom: 1px solid var(--border);
      margin-bottom: 1.25rem;
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
    .sort-icon {
      font-size: .7rem;
      color: var(--muted);
      cursor: pointer;
    }
    .stats-table th {
      cursor: pointer;
      user-select: none;
    }
    .stats-table th:hover {
      color: var(--fg);
    }
    .stats-table th[data-sort].asc::after {
      content: ' ↑';
      color: var(--accent);
    }
    .stats-table th[data-sort].desc::after {
      content: ' ↓';
      color: var(--accent);
    }
  </style>
  
  <script>
    // Pestañas
    document.addEventListener('DOMContentLoaded', () => {
      // Activar pestaña desde URL hash o parámetro GET
      const urlParams = new URLSearchParams(window.location.search);
      const activeTab = urlParams.get('tab') || 'diario';
      
      // Activar pestaña y panel correspondientes
      const activeTabElement = document.querySelector(`.stats-tab[data-tab="${activeTab}"]`);
      if (activeTabElement) {
        document.querySelectorAll('.stats-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.stats-panel').forEach(p => p.classList.remove('active'));
        activeTabElement.classList.add('active');
        document.getElementById('tab-' + activeTab).classList.add('active');
      }
      
      document.querySelectorAll('.stats-tab').forEach(tab => {
        tab.addEventListener('click', () => {
          document.querySelectorAll('.stats-tab').forEach(t => t.classList.remove('active'));
          document.querySelectorAll('.stats-panel').forEach(p => p.classList.remove('active'));
          tab.classList.add('active');
          document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
        });
      });
    });
=======

    // Ordenamiento de tablas
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('th[data-sort]').forEach(th => {
        th.addEventListener('click', (e) => {
          const table = th.closest('table');
          const tbody = table.querySelector('tbody');
          const columnIndex = Array.from(th.parentNode.children).indexOf(th);
          const sortKey = th.dataset.sort;
          
          // Determinar dirección: si ya tiene clase 'asc', pasamos a 'desc', y viceversa
          const currentDir = th.classList.contains('asc') ? 'asc' : 
                            (th.classList.contains('desc') ? 'desc' : null);
          const nextDir = !currentDir ? 'asc' : (currentDir === 'asc' ? 'desc' : 'asc');
          
          // Remover clases de todos los th de esta tabla
          table.querySelectorAll('th[data-sort]').forEach(h => {
            h.classList.remove('asc', 'desc');
          });
          th.classList.add(nextDir);
          
          //get rows
          const rows = Array.from(tbody.querySelectorAll('tr'));
          const isNumeric = sortKey === 'count' || sortKey === 'year' || sortKey === 'total';
          
          // Sort by data-sort-value or by the column index
          const direction = nextDir === 'asc' ? 1 : -1;
          rows.sort((a, b) => {
            // Use data-sort-value if available, otherwise use text content
            const aVal = a.children[columnIndex].dataset.sortValue ?? a.children[columnIndex].textContent.trim();
            const bVal = b.children[columnIndex].dataset.sortValue ?? b.children[columnIndex].textContent.trim();
            
            if (isNumeric) {
              const numA = parseFloat(aVal.replace(/[^0-9.-]/g, '')) || 0;
              const numB = parseFloat(bVal.replace(/[^0-9.-]/g, '')) || 0;
              return (numA - numB) * direction;
            }
            return aVal.localeCompare(bVal, undefined, {numeric: true}) * direction;
          });
          
          // Reinsertar filas ordenadas
          rows.forEach(row => tbody.appendChild(row));
        });
      });
    });
  </script>
</body>
</html>
