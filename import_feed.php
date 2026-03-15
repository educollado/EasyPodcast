<?php

declare(strict_types=1);

// Página de importación de feed RSS externo.
// Flujo: GET → formulario | POST action=preview → previsualización | POST action=import → streaming.

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/import_feed_handler.php';

session_start();
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
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Importar feed RSS') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
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
          <div class="actions" style="margin-top:1rem">
            <button class="btn" type="submit">Vista previa</button>
          </div>
        </form>

      <?php else: ?>
        <?php
          $podcast  = $previewData['podcast'];
          $episodes = $previewData['episodes'];

          // Cargar datos actuales del podcast para comparación
          $pdoPreview     = new PDO('sqlite:' . $dbPath);
          $currentPodcast = $pdoPreview->query('SELECT * FROM podcast LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
          unset($pdoPreview);

          // Campos mapeables del feed → etiqueta
          // 'link' no aparece: la URL principal siempre se toma del host actual, no del feed.
          $metaFields = [
              'title'       => 'Título',
              'description' => 'Descripción',
              'language'    => 'Idioma',
              'author'      => 'Autor',
              'owner_name'  => 'Owner name',
              'owner_email' => 'Owner email',
              'category'    => 'Categoría',
              'explicit'    => 'Explícito',
              'itunes_type' => 'Tipo iTunes',
              'copyright'   => 'Copyright',
              'image_url'   => 'Imagen',
          ];

          // Helper: valor legible para mostrar en tabla
          $displayVal = static function (string $field, $raw): string {
              $v = (string) $raw;
              if ($field === 'explicit') { return $v === '1' || $v === 'true' ? 'Sí' : 'No'; }
              if ($field === 'description') { return mb_strimwidth($v, 0, 150, '…'); }
              return $v;
          };
        ?>

        <!-- Formulario unificado: metadatos + episodios + opciones -->
        <form method="post" action="import_feed.php" autocomplete="off" id="import-form">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="import">
          <input type="hidden" name="feed_url" value="<?= esc($feedUrl) ?>">

          <!-- Tabla de comparación de metadatos -->
          <h2 style="margin-top:1.5rem">Metadatos del podcast</h2>
          <p style="margin-bottom:.75rem;font-size:.9rem">
            Marca los campos que quieres sobreescribir con los valores del feed.
          </p>
          <div style="overflow-x:auto;margin-bottom:1.5rem">
            <table style="width:100%;border-collapse:collapse;font-size:.9rem">
              <thead>
                <tr>
                  <th style="padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd);width:2rem">
                    <input type="checkbox" id="select-all-meta" checked title="Seleccionar todos los campos">
                  </th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd);width:14%">Campo</th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">Valor actual</th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">Valor del feed</th>
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
                    <td style="padding:.3rem .5rem;text-align:center;vertical-align:top">
                      <input type="checkbox" name="overwrite_fields[]" value="<?= esc($field) ?>"
                             <?= $hasValue ? 'checked' : '' ?>>
                    </td>
                    <td style="padding:.3rem .5rem;font-weight:600;vertical-align:top;white-space:nowrap"><?= esc($label) ?></td>
                    <td style="padding:.3rem .5rem;vertical-align:top;word-break:break-word;color:var(--muted,#666)">
                      <?= $currentVal !== '' ? esc($currentVal) : '<em>—</em>' ?>
                    </td>
                    <td style="padding:.3rem .5rem;vertical-align:top;word-break:break-word">
                      <?= $feedVal !== '' && $feedVal !== 'No' ? esc($feedVal) : '<span style="color:var(--muted,#999)">' . esc($feedVal ?: '—') . '</span>' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Tabla de episodios -->
          <h2>Episodios encontrados (<?= count($episodes) ?>)</h2>
          <div style="overflow-x:auto;margin-bottom:1.5rem">
            <table style="width:100%;border-collapse:collapse;font-size:.9rem">
              <thead>
                <tr>
                  <th style="padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd);width:2rem">
                    <input type="checkbox" id="select-all-eps" checked title="Seleccionar todos los episodios">
                  </th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">#</th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">Título</th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">Fecha</th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">Duración</th>
                  <th style="text-align:center;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">Imagen</th>
                  <th style="text-align:left;padding:.3rem .5rem;border-bottom:2px solid var(--border,#ddd)">Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($episodes as $i => $ep): ?>
                  <tr>
                    <td style="padding:.3rem .5rem;text-align:center">
                      <input type="checkbox" name="selected_guids[]" value="<?= esc($ep['guid']) ?>" checked>
                    </td>
                    <td style="padding:.3rem .5rem"><?= $i + 1 ?></td>
                    <td style="padding:.3rem .5rem"><?= esc($ep['title'] !== '' ? $ep['title'] : __('(sin título)')) ?></td>
                    <td style="padding:.3rem .5rem"><?= esc($ep['pub_date'] ?? '') ?></td>
                    <td style="padding:.3rem .5rem"><?= esc($ep['duration']) ?></td>
                    <td style="padding:.3rem .5rem;text-align:center" title="<?= $ep['image_url'] !== '' ? esc($ep['image_url']) : esc(__('Sin imagen')) ?>">
                      <?= $ep['image_url'] !== '' ? '✓' : '<span style="color:var(--muted,#999)">—</span>' ?>
                    </td>
                    <td style="padding:.3rem .5rem"><?= esc($ep['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <fieldset style="border:1px solid var(--border,#ddd);padding:1rem;margin-bottom:1rem;border-radius:4px">
            <legend style="padding:0 .5rem;font-weight:600">Opciones de importación</legend>
            <label style="display:flex;align-items:center;gap:.5rem;font-weight:400">
              <input type="checkbox" name="import_duplicates">
              <?= __('Importar episodios aunque su GUID ya exista en la base de datos') ?>
            </label>
          </fieldset>

          <div class="notice" style="margin-bottom:1rem">
            <?= __('⚠ La importación puede tardar varios minutos dependiendo del número de episodios y el tamaño de los audios. No cierres esta página hasta que finalice.') ?>
          </div>

          <div class="actions">
            <a class="btn" href="import_feed.php">Cancelar</a>
            <button class="btn" type="submit" id="import-btn"><?= __('Iniciar importación') ?></button>
          </div>
        </form>

        <script>
          (function () {
            var form = document.getElementById('import-form');
            var btn  = document.getElementById('import-btn');

            // Fábrica de lógica "seleccionar todos" para un grupo de checkboxes
            function bindSelectAll(selectAllId, childName) {
              var master = document.getElementById(selectAllId);
              if (!master) { return; }

              // Sincronizar estado inicial del maestro con el estado real de los hijos
              function syncMaster() {
                var all     = form.querySelectorAll('input[name="' + childName + '"]');
                var checked = form.querySelectorAll('input[name="' + childName + '"]:checked');
                master.checked       = checked.length === all.length;
                master.indeterminate = checked.length > 0 && checked.length < all.length;
              }
              syncMaster();

              master.addEventListener('change', function () {
                form.querySelectorAll('input[name="' + childName + '"]').forEach(function (cb) {
                  cb.checked = master.checked;
                });
              });

              form.addEventListener('change', function (e) {
                if (e.target.name !== childName) { return; }
                syncMaster();
              });
            }

            bindSelectAll('select-all-meta', 'overwrite_fields[]');
            bindSelectAll('select-all-eps',  'selected_guids[]');

            // Validar y deshabilitar botón al enviar
            if (form && btn) {
              form.addEventListener('submit', function (e) {
                var checked = form.querySelectorAll('input[name="selected_guids[]"]:checked');
                if (checked.length === 0) {
                  e.preventDefault();
                  alert('Selecciona al menos un episodio para importar.');
                  return;
                }
                btn.disabled    = true;
                btn.textContent = 'Importando…';
              });
            }
          }());
        </script>
      <?php endif; ?>

      <?php endif; // curl disponible ?>

    </main>
  </div>
</body>
</html>
