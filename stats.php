<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/stats_handler.php';
require_once __DIR__ . '/lib/stats_downloads_handler.php';

startSecureSession();
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

    $downloadsData = getDownloadsStatsData($pdo, $filterYear);
    $filterYear = $downloadsData['filter_year'];
    $dailyStats = $downloadsData['daily']['items'];
    $monthlyStats = $downloadsData['monthly']['items'];
    $yearlyStats = $downloadsData['yearly']['items'];
    $totalByEpisode = $downloadsData['summary']['items'];
    $availableYears = $downloadsData['available_years'];
} catch (Throwable $e) {
    $dailyStats = [];
    $monthlyStats = [];
    $yearlyStats = [];
    $totalByEpisode = [];
    $availableYears = [];
    $downloadsError = $e->getMessage();
}

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
          <span class="stat-value stat-compact">
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
                    <td><?= esc((string) ($stat['display_date'] ?? formatStatsDate((string) ($stat['download_date'] ?? '')))) ?></td>
                    <td><?= esc($stat['episode_title']) ?></td>
                    <td>
                      <span class="action-type-badge">
                        <?= esc((string) ($stat['action_type_label'] ?? getActionTypeLabel((string) ($stat['action_type'] ?? 'download')))) ?>
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
              <select name="year" data-submit-on-change="1">
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
                    <td data-sort-value="<?= (int)$stat['anio'] * 12 + (int)$stat['mes'] ?>"><?= esc((string) ($stat['period_label'] ?? formatMonthYear((int) $stat['anio'], (int) $stat['mes']))) ?></td>
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
        <h3 class="section-subtitle no-top"><?= __('Total de descargas y reproducciones por capítulo') ?></h3>
        
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
  <script src="/assets/js/stats.js"></script>
</body>
</html>
