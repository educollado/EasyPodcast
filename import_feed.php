<?php

declare(strict_types=1);

// Página de importación de feed RSS externo.
// Flujo: GET → formulario | POST action=preview → previsualización | POST action=import → streaming.

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/import_feed_handler.php';

startSecureSession();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$action = $_POST['action'] ?? '';

// --- POST action=import: streaming completo, sale con exit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import') {
    csrf_verify();
    $feedUrl        = trim((string) ($_POST['feed_url'] ?? ''));
    $skipExisting   = !isset($_POST['import_duplicates']);
    $selectedGuids  = isset($_POST['selected_guids']) && is_array($_POST['selected_guids'])
        ? array_map('strval', $_POST['selected_guids'])
        : [];
    $overwriteFields = isset($_POST['overwrite_fields']) && is_array($_POST['overwrite_fields'])
        ? array_map('strval', $_POST['overwrite_fields'])
        : [];
    runFeedImport($dbPath, $feedUrl, $skipExisting, __DIR__ . '/admin_nav.php', $selectedGuids, $overwriteFields);
    // runFeedImport llama a exit internamente
}

// --- POST action=preview ---
$previewData = null;
$previewError = '';
$feedUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preview') {
    csrf_verify();
    $feedUrl = trim((string) ($_POST['feed_url'] ?? ''));
    $result = loadFeedPreview($feedUrl);
    if ($result['error'] !== '') {
        $previewError = $result['error'];
    } else {
        $previewData = $result['preview'];
    }
}
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Importar feed RSS') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'import'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Importar feed RSS') ?></h1>
      <p><?= __('Importa episodios desde una URL de feed RSS externo. Los archivos MP3 e imágenes se descargarán localmente.') ?></p>

      <?php if (!function_exists('curl_init')): ?>
        <div class="error">
          <strong><?= __('La extensión cURL no está disponible.') ?></strong><br>
          <?= __('La importación de feeds requiere cURL para descargar el feed, los audios y las imágenes. Habilita la extensión <code>curl</code> en tu instalación de PHP y recarga esta página para poder importar.') ?>
        </div>
      <?php else: ?>

      <?php if ($previewError !== ''): ?>
        <div class="error"><?= esc($previewError) ?></div>
      <?php endif; ?>

      <?php if ($previewData === null): ?>
        <!-- Estado 1: Formulario inicial -->
        <form method="post" action="import_feed.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="preview">
          <label>
            <?= __('URL del feed RSS') ?>
            <input type="url" name="feed_url" value="<?= esc($feedUrl) ?>"
                   placeholder="https://ejemplo.com/feed.xml" required autofocus>
          </label>
          <div class="actions section-gap-md">
            <button class="btn" type="submit"><?= __('Vista previa') ?></button>
          </div>
        </form>

      <?php else: ?>
        <?php
          $podcast  = $previewData['podcast'];
          $episodes = $previewData['episodes'];

          // Cargar datos actuales del podcast para comparación
          $pdoPreview     = new PDO('sqlite:' . $dbPath);
          $currentPodcast = activePodcast($pdoPreview) ?? [];
          unset($pdoPreview);

          // Campos mapeables del feed → etiqueta
          // 'link' no aparece: la URL principal siempre se toma del host actual, no del feed.
          $metaFields = [
              'title'       => __('Título'),
              'description' => __('Descripción'),
              'language'    => __('Idioma'),
              'author'      => __('Autor'),
              'owner_name'  => __('Owner name'),
              'owner_email' => __('Owner email'),
              'category'    => __('Categoría'),
              'explicit'    => __('Explícito'),
              'itunes_type' => __('Tipo iTunes'),
              'copyright'   => __('Copyright'),
              'image_url'   => __('Imagen'),
          ];

          // Helper: valor legible para mostrar en tabla
          $displayVal = static function (string $field, $raw): string {
              $v = (string) $raw;
              if ($field === 'explicit') { return $v === '1' || $v === 'true' ? __('Sí') : __('No'); }
              if ($field === 'description') { return mb_strimwidth($v, 0, 150, '…'); }
              return $v;
          };
        ?>

        <!-- Formulario unificado: metadatos + episodios + opciones -->
        <form method="post" action="import_feed.php" autocomplete="off" id="import-form"
              data-empty-selection-message="<?= esc(__('Selecciona al menos un episodio para importar.')) ?>"
              data-importing-label="<?= esc(__('Importando…')) ?>">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="import">
          <input type="hidden" name="feed_url" value="<?= esc($feedUrl) ?>">

          <!-- Tabla de comparación de metadatos -->
          <h2 class="import-preview-title"><?= __('Metadatos del podcast') ?></h2>
          <p class="import-preview-note">
            <?= __('Marca los campos que quieres sobreescribir con los valores del feed.') ?>
          </p>
          <div class="import-preview-wrap">
            <table class="import-preview-table">
              <thead>
                <tr>
                  <th class="import-cell-check">
                    <input type="checkbox" id="select-all-meta" checked title="<?= esc(__('Seleccionar todos los campos')) ?>">
                  </th>
                  <th class="import-cell-label text-left"><?= __('Campo') ?></th>
                  <th class="text-left"><?= __('Valor actual') ?></th>
                  <th class="text-left"><?= __('Valor del feed') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($metaFields as $field => $label):
                  $currentVal = $displayVal($field, $currentPodcast[$field] ?? '');
                  $feedVal    = $displayVal($field, $podcast[$field] ?? '');
                  $feedRaw    = (string) ($podcast[$field] ?? '');
                  $hasValue   = $feedRaw !== '' && $feedRaw !== '0';
                ?>
                  <tr>
                    <td class="import-cell-check">
                      <input type="checkbox" name="overwrite_fields[]" value="<?= esc($field) ?>"
                             <?= $hasValue ? 'checked' : '' ?>>
                    </td>
                    <td class="import-cell-label"><?= esc($label) ?></td>
                    <td class="import-cell-value import-cell-muted">
                      <?= $currentVal !== '' ? esc($currentVal) : '<em>—</em>' ?>
                    </td>
                    <td class="import-cell-value">
                      <?= $hasValue ? esc($feedVal) : '<span class="import-empty-value">' . esc($feedVal ?: '—') . '</span>' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Tabla de episodios -->
          <h2><?= __('Episodios encontrados (%d)', count($episodes)) ?></h2>
          <div class="import-preview-wrap">
            <table class="import-preview-table">
              <thead>
                <tr>
                  <th class="import-cell-check">
                    <input type="checkbox" id="select-all-eps" checked title="<?= esc(__('Seleccionar todos los episodios')) ?>">
                  </th>
                  <th class="text-left">#</th>
                  <th class="text-left"><?= __('Título') ?></th>
                  <th class="text-left"><?= __('Fecha') ?></th>
                  <th class="text-left"><?= __('Duración') ?></th>
                  <th class="import-cell-center"><?= __('Imagen') ?></th>
                  <th class="text-left"><?= __('Estado') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($episodes as $i => $ep): ?>
                  <tr>
                    <td class="import-cell-check">
                      <input type="checkbox" name="selected_guids[]" value="<?= esc($ep['guid']) ?>" checked>
                    </td>
                    <td><?= $i + 1 ?></td>
                    <td><?= esc($ep['title'] !== '' ? $ep['title'] : __('(sin título)')) ?></td>
                    <td><?= esc($ep['pub_date'] ?? '') ?></td>
                    <td><?= esc($ep['duration']) ?></td>
                    <td class="import-cell-center" title="<?= $ep['image_url'] !== '' ? esc($ep['image_url']) : esc(__('Sin imagen')) ?>">
                      <?= $ep['image_url'] !== '' ? '✓' : '<span class="import-empty-value">—</span>' ?>
                    </td>
                    <td><?= esc($ep['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <fieldset class="import-options">
            <legend><?= __('Opciones de importación') ?></legend>
            <label class="import-options-row">
              <input type="checkbox" name="import_duplicates">
              <?= __('Importar episodios aunque su GUID ya exista en la base de datos') ?>
            </label>
          </fieldset>

          <div class="notice">
            <?= __('⚠ La importación puede tardar varios minutos dependiendo del número de episodios y el tamaño de los audios. No cierres esta página hasta que finalice.') ?>
          </div>

          <div class="actions">
            <a class="btn" href="import_feed.php"><?= __('Cancelar') ?></a>
            <button class="btn" type="submit" id="import-btn"><?= __('Iniciar importación') ?></button>
          </div>
        </form>

      <?php endif; ?>

      <?php endif; // curl disponible ?>

    </main>
  </div>
  <script src="/assets/js/import_feed.js"></script>
</body>
</html>
