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

const STATS_ROWS_PER_PAGE = 100;

/**
 * Construye una URL de stats.php preservando los filtros actuales.
 *
 * @param array<string, int|string|null> $overrides
 */
function buildStatsUrl(array $overrides = []): string
{
    $params = $_GET;

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }

        if (str_ends_with($key, '_page') && (int) $value <= 1) {
            unset($params[$key]);
            continue;
        }

        $params[$key] = (string) $value;
    }

    $query = http_build_query($params);
    return 'stats.php' . ($query !== '' ? '?' . $query : '');
}

/**
 * Calcula una ventana compacta de páginas para la paginación.
 *
 * @return array<int|null>
 */
function getStatsPaginationPages(int $currentPage, int $totalPages): array
{
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }

    $pages = [1, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages];
    $pages = array_values(array_unique(array_filter($pages, static function (int $page) use ($totalPages): bool {
        return $page >= 1 && $page <= $totalPages;
    })));
    sort($pages);

    $window = [];
    $previous = null;
    foreach ($pages as $page) {
        if ($previous !== null && $page > $previous + 1) {
            $window[] = null;
        }
        $window[] = $page;
        $previous = $page;
    }

    return $window;
}

/**
 * Renderiza la paginación de una tabla de estadísticas.
 *
 * @param array{
 *   rows: array<int, array<string, mixed>>,
 *   page: int,
 *   per_page: int,
 *   total_rows: int,
 *   total_pages: int,
 *   from: int,
 *   to: int
 * } $pagination
 */
function renderStatsPagination(string $tab, string $pageParam, array $pagination): void
{
    if (($pagination['total_rows'] ?? 0) === 0) {
        return;
    }

    $currentPage = (int) $pagination['page'];
    $totalPages = (int) $pagination['total_pages'];
    $hrefBase = '#tab-' . $tab;

    echo '<div class="stats-pagination">';
    echo '<div class="stats-pagination-info">' . esc(__('Mostrando %d-%d de %d', (int) $pagination['from'], (int) $pagination['to'], (int) $pagination['total_rows'])) . '</div>';

    if ($totalPages > 1) {
        echo '<div class="stats-pagination-links">';

        if ($currentPage > 1) {
            $prevUrl = buildStatsUrl(['tab' => $tab, $pageParam => $currentPage - 1]) . $hrefBase;
            echo '<a class="stats-page-link" href="' . esc($prevUrl) . '">' . esc(__('Anterior')) . '</a>';
        }

        foreach (getStatsPaginationPages($currentPage, $totalPages) as $page) {
            if ($page === null) {
                echo '<span class="stats-page-gap">…</span>';
                continue;
            }

            if ($page === $currentPage) {
                echo '<span class="stats-page-current">' . esc((string) $page) . '</span>';
                continue;
            }

            $pageUrl = buildStatsUrl(['tab' => $tab, $pageParam => $page]) . $hrefBase;
            echo '<a class="stats-page-link" href="' . esc($pageUrl) . '">' . esc((string) $page) . '</a>';
        }

        if ($currentPage < $totalPages) {
            $nextUrl = buildStatsUrl(['tab' => $tab, $pageParam => $currentPage + 1]) . $hrefBase;
            echo '<a class="stats-page-link" href="' . esc($nextUrl) . '">' . esc(__('Siguiente')) . '</a>';
        }

        echo '</div>';
    }

    echo '</div>';
}

// Datos de descargas
$filterYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
$downloadsError = '';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $dailyStats = getDailyStats($pdo);
    $monthlyStats = getMonthlyStats($pdo, $filterYear > 0 ? $filterYear : null);
    $yearlyStats = getYearlyStats($pdo);
    $totalByEpisode = getTotalDownloadsByEpisode($pdo);
    $availableYears = getAvailableYears($pdo);
} catch (Throwable $e) {
    $dailyStats = [];
    $monthlyStats = [];
    $yearlyStats = [];
    $totalByEpisode = [];
    $availableYears = [];
    $downloadsError = $e->getMessage();
}

$totalByEpisode = array_values(array_filter($totalByEpisode, static function (array $stat): bool {
    return (int) ($stat['total_downloads'] ?? 0) > 0;
}));

$dailyPagination = paginateStatsRows($dailyStats, getStatsPageNumber('diario_page', $_GET), STATS_ROWS_PER_PAGE);
$monthlyPagination = paginateStatsRows($monthlyStats, getStatsPageNumber('mensual_page', $_GET), STATS_ROWS_PER_PAGE);
$yearlyPagination = paginateStatsRows($yearlyStats, getStatsPageNumber('anual_page', $_GET), STATS_ROWS_PER_PAGE);
$summaryPagination = paginateStatsRows($totalByEpisode, getStatsPageNumber('resumen_page', $_GET), STATS_ROWS_PER_PAGE);
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
          <span class="stat-value stat-last-title"><?= esc($lastTitle) ?></span>
          <?php if ($lastPubDate !== ''): ?>
            <span class="stat-sub"><?= esc($lastPubDate) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>

      <h2 class="section-subtitle"><?= __('Caché') ?></h2>
      <div class="stats-grid">

        <div class="stat-card">
          <span class="stat-label"><?= __('Estado') ?></span>
          <span class="stat-value" style="font-size:1.2rem;">
            <?php if ($cacheEnabled): ?>
              <span class="status-active"><?= __('Activa') ?></span>
            <?php else: ?>
              <span class="status-inactive"><?= __('Inactiva') ?></span>
            <?php endif; ?>
          </span>
          <span class="stat-sub"><a href="cache_management.php" class="accent-link"><?= __('Gestionar caché') ?></a></span>
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

      <h2 class="section-subtitle"><?= __('Estadísticas de descargas') ?></h2>
      
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
        <p class="stats-note">
          <?php
            $count = (int) $dailyPagination['total_rows'];
            echo esc(__('Últimas %d descargas y reproducciones (hasta 7 días)', $count));
          ?>
        </p>
        
        <?php if ((int) $dailyPagination['total_rows'] === 0): ?>
          <div class="empty-state"><?= __('Aún no hay datos de descargas o reproducciones') ?></div>
        <?php else: ?>
          <div class="table-wrap">
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
                <?php foreach ($dailyPagination['rows'] as $stat): ?>
                  <tr>
                    <td><?= esc(formatStatsDate($stat['download_date'])) ?></td>
                    <td><?= esc($stat['episode_title']) ?></td>
                    <td>
                      <span class="action-type-badge">
                        <?= esc(getActionTypeLabel($stat['action_type'] ?? 'download')) ?? esc(__('Descarga')) ?>
                      </span>
                    </td>
                    <td class="ip-tag"><?= esc($stat['ip_address']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php renderStatsPagination('diario', 'diario_page', $dailyPagination); ?>
        <?php endif; ?>
      </div>

      <!-- Pestaña Mensual -->
      <div id="tab-mensual" class="stats-panel">
        <?php if (!empty($availableYears)): ?>
          <div class="year-selector">
            <form method="get" class="inline-form">
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

        <?php if ((int) $monthlyPagination['total_rows'] === 0): ?>
          <div class="empty-state"><?= __('Aún no hay datos mensuales') ?></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="stats-table">
              <thead>
                <tr>
                  <th data-sort="period"><?= __('Año/Mes') ?> <span class="sort-icon">↕</span></th>
                  <th data-sort="title"><?= __('Capítulo') ?> <span class="sort-icon">↕</span></th>
                  <th data-sort="count" class="text-right"><?= __('Descargas') ?> <span class="sort-icon">↕</span></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($monthlyPagination['rows'] as $stat): ?>
                  <tr>
                    <td data-sort-value="<?= (int)$stat['anio'] * 12 + (int)$stat['mes'] ?>"><?= esc(formatMonthYear((int)$stat['anio'], (int)$stat['mes'])) ?></td>
                    <td><?= esc($stat['episode_title']) ?></td>
                    <td data-sort-value="<?= (int)$stat['descargas'] ?>" class="text-right"><span class="total-badge"><?= (int)$stat['descargas'] ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php renderStatsPagination('mensual', 'mensual_page', $monthlyPagination); ?>
        <?php endif; ?>
      </div>

      <!-- Pestaña Anual -->
      <div id="tab-anual" class="stats-panel">
        <?php if ((int) $yearlyPagination['total_rows'] === 0): ?>
          <div class="empty-state"><?= __('Aún no hay datos anuales') ?></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="stats-table">
              <thead>
                <tr>
                  <th data-sort="year"><?= __('Año') ?> <span class="sort-icon">↕</span></th>
                  <th data-sort="title"><?= __('Capítulo') ?> <span class="sort-icon">↕</span></th>
                  <th data-sort="count" class="text-right"><?= __('Descargas') ?> <span class="sort-icon">↕</span></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($yearlyPagination['rows'] as $stat): ?>
                  <tr>
                    <td data-sort-value="<?= (int)$stat['anio'] ?>"><?= esc((string)$stat['anio']) ?></td>
                    <td><?= esc($stat['episode_title']) ?></td>
                    <td data-sort-value="<?= (int)$stat['descargas'] ?>" class="text-right"><span class="total-badge"><?= (int)$stat['descargas'] ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php renderStatsPagination('anual', 'anual_page', $yearlyPagination); ?>
        <?php endif; ?>
      </div>

      <!-- Pestaña Resumen -->
      <div id="tab-resumen" class="stats-panel">
        <h3 class="section-subtitle" style="margin-top: 0;"><?= __('Total de descargas y reproducciones por capítulo') ?></h3>
        
        <?php if ((int) $summaryPagination['total_rows'] === 0): ?>
          <div class="empty-state"><?= __('Aún no hay descargas ni reproducciones registradas') ?></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="stats-table">
              <thead>
                <tr>
                  <th data-sort="title"><?= __('Capítulo') ?> <span class="sort-icon">↕</span></th>
                  <th data-sort="total" class="text-right"><?= __('Total') ?> <span class="sort-icon">↕</span></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($summaryPagination['rows'] as $stat): ?>
                  <tr>
                    <td><?= esc($stat['episode_title']) ?></td>
                    <td data-sort-value="<?= (int)$stat['total_downloads'] ?>" class="text-right"><span class="total-badge"><?= (int)$stat['total_downloads'] ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php renderStatsPagination('resumen', 'resumen_page', $summaryPagination); ?>
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
      min-width: 600px;
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
    .action-type-badge {
      font-size: .8rem;
      padding: .2rem .4rem;
      border-radius: var(--radius);
      background: var(--bg);
      color: var(--fg);
    }
    .text-right { text-align: right; }
    .section-subtitle {
      margin-top: 2rem;
      font-size: 1.05rem;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .06em;
    }
    .stat-title { font-size: 1.1rem; font-weight: 600; }
    .status-active { color: #059669; }
    .status-inactive { color: var(--muted); }
    .stats-note {
      color: var(--muted);
      font-size: .9rem;
      margin-bottom: 1rem;
    }
    .inline-form { display: inline; }
    .accent-link { color: var(--accent); }
    .stat-last-title { font-size: 1.1rem; font-weight: 600; }
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
    .stats-pagination {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      margin-top: 1rem;
      flex-wrap: wrap;
    }
    .stats-pagination-info {
      color: var(--muted);
      font-size: .9rem;
    }
    .stats-pagination-links {
      display: flex;
      gap: .4rem;
      align-items: center;
      flex-wrap: wrap;
    }
    .stats-page-link,
    .stats-page-current,
    .stats-page-gap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2.25rem;
      height: 2.25rem;
      padding: 0 .75rem;
      border-radius: var(--radius);
      font-size: .9rem;
    }
    .stats-page-link {
      border: 1px solid var(--border);
      color: var(--fg);
      background: var(--bg);
      text-decoration: none;
    }
    .stats-page-link:hover {
      border-color: var(--accent);
      color: var(--accent);
    }
    .stats-page-current {
      background: var(--accent);
      color: var(--accent-contrast, #fff);
      font-weight: 600;
    }
    .stats-page-gap {
      color: var(--muted);
    }
  </style>
  
  <script>
    // Inicialización al cargar la página
    document.addEventListener('DOMContentLoaded', () => {
      // ==================== PESTAÑAS ====================
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

          const nextUrl = new URL(window.location.href);
          nextUrl.searchParams.set('tab', tab.dataset.tab);
          window.history.replaceState({}, '', nextUrl);
        });
      });

      // ==================== ORDENAMIENTO DE TABLAS ====================
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
          
          // Obtener filas
          const rows = Array.from(tbody.querySelectorAll('tr'));
          const isNumeric = sortKey === 'count' || sortKey === 'year' || sortKey === 'total';
          
          // Ordenar por data-sort-value o por el contenido de texto
          const direction = nextDir === 'asc' ? 1 : -1;
          rows.sort((a, b) => {
            // Usar data-sort-value si está disponible, de lo contrario usar contenido de texto
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
