<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

// URL base para los ejemplos curl.
$baseUrl = rtrim((string) ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio.com'), '/');
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc(__('Documentación API REST')) ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <style>
    .api-section { margin: 2rem 0; border-top: 1px solid var(--border, #e0e0e0); padding-top: 1.5rem; }
    .endpoint-block { background: var(--bg-alt, #f8f8f8); border-radius: 6px; padding: 1rem 1.2rem; margin: 1rem 0; }
    .method { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 0.8em; margin-right: 6px; }
    .method-get    { background: #d1fae5; color: #065f46; }
    .method-post   { background: #dbeafe; color: #1e40af; }
    .method-delete { background: #fee2e2; color: #991b1b; }
    pre { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: 0.82em; }
    .toc { column-count: 2; column-gap: 2rem; }
    .toc a { display: block; padding: 2px 0; }
    .params-table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
    .params-table th, .params-table td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--border, #e0e0e0); }
    .params-table th { font-weight: 600; }
    code.inline { background: var(--bg-alt, #f0f0f0); padding: 1px 4px; border-radius: 3px; font-size: 0.9em; }
  </style>
</head>
<body>
  <?php $currentAdminPage = 'api_docs'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Documentación API REST') ?></h1>
      <p><?= __('La API REST permite gestionar el podcast de forma programática. Todas las peticiones requieren autenticación.') ?></p>

      <h2><?= __('Autenticación') ?></h2>
      <p><?= __('Incluye tu token en la cabecera') ?> <code class="inline">Authorization</code>:</p>
      <pre>Authorization: Bearer &lt;tu-token&gt;</pre>
      <p><?= __('Gestiona tus tokens en') ?> <a href="api_tokens.php"><?= __('Tokens de API') ?></a>.</p>

      <h2><?= __('Formato de respuesta') ?></h2>
      <p><?= __('Todas las respuestas son JSON:') ?></p>
      <pre>{
  "success": true,
  "data": { ... }
}

// <?= __('En caso de error:') ?>

{
  "success": false,
  "error": "<?= __('Descripción del error') ?>"
}</pre>

      <h2>Base URL</h2>
      <pre><?= esc($baseUrl) ?>/api/v1</pre>

      <!-- Tabla de contenidos -->
      <h2>Endpoints</h2>
      <div class="toc">
        <a href="#episodes"><?= __('Episodios') ?></a>
        <a href="#podcast"><?= __('Podcast') ?></a>
        <a href="#pages"><?= __('Páginas') ?></a>
        <a href="#social"><?= __('Redes sociales') ?></a>
        <a href="#cache"><?= __('Caché') ?></a>
        <a href="#stats"><?= __('Estadísticas') ?></a>
        <a href="#feed"><?= __('Feed') ?></a>
        <a href="#system"><?= __('Sistema') ?></a>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="episodes">
        <h2><?= __('Episodios') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/episodes</code> — <?= __('Lista paginada') ?></p>
          <table class="params-table">
            <tr><th><?= __('Parámetro') ?></th><th><?= __('Tipo') ?></th><th><?= __('Descripción') ?></th></tr>
            <tr><td>page</td><td>int</td><td><?= __('Página (defecto: 1)') ?></td></tr>
            <tr><td>limit</td><td>int</td><td><?= __('Resultados por página, máx. 100 (defecto: 20)') ?></td></tr>
            <tr><td>status</td><td>string</td><td><code class="inline">draft</code> <?= __('o') ?> <code class="inline">published</code> (<?= __('opcional') ?>)</td></tr>
          </table>
          <pre>curl -s -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/episodes?status=published&amp;page=1"</pre>
          <pre>{
  "success": true,
  "data": {
    "items": [ { "id": 1, "title": "...", "status": "published", ... } ],
    "total": 42,
    "page": 1,
    "limit": 20,
    "total_pages": 3
  }
}</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/episodes/{id}</code> — <?= __('Obtener episodio') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/episodes/1"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/episodes</code> — <?= __('Crear episodio') ?></p>
          <p><?= __('Acepta') ?> <code class="inline">application/json</code> <?= __('o') ?> <code class="inline">multipart/form-data</code> (<?= __('para subir ficheros') ?>).</p>
          <table class="params-table">
            <tr><th><?= __('Campo') ?></th><th><?= __('Req.') ?></th><th><?= __('Descripción') ?></th></tr>
            <tr><td>title</td><td>✓</td><td><?= __('Título del episodio') ?></td></tr>
            <tr><td>description</td><td>✓</td><td><?= __('Descripción') ?></td></tr>
            <tr><td>audio_url</td><td>✓*</td><td><?= __('URL del audio (*o subir audio_file)') ?></td></tr>
            <tr><td>audio_size_bytes</td><td>✓*</td><td><?= __('Tamaño en bytes (*si no se sube fichero)') ?></td></tr>
            <tr><td>audio_mime_type</td><td></td><td><?= __('MIME del audio (defecto: audio/mpeg)') ?></td></tr>
            <tr><td>status</td><td></td><td><code class="inline">draft</code> (<?= __('defecto') ?>) <?= __('o') ?> <code class="inline">published</code></td></tr>
            <tr><td>pub_date</td><td></td><td><?= __('Fecha ISO 8601 (auto-asignada al publicar)') ?></td></tr>
            <tr><td>audio_file</td><td></td><td><?= __('Fichero de audio (multipart)') ?></td></tr>
            <tr><td>image_file</td><td></td><td><?= __('Imagen del episodio (multipart)') ?></td></tr>
            <tr><td>image_url</td><td></td><td><?= __('URL de imagen alternativa') ?></td></tr>
            <tr><td>season_number</td><td></td><td><?= __('Número de temporada') ?></td></tr>
            <tr><td>episode_number</td><td></td><td><?= __('Número de episodio') ?></td></tr>
            <tr><td>episode_type</td><td></td><td><code class="inline">full</code>, <code class="inline">trailer</code> <?= __('o') ?> <code class="inline">bonus</code></td></tr>
            <tr><td>explicit</td><td></td><td>0 = <?= __('no') ?>, 1 = <?= __('sí') ?>, "" = <?= __('hereda del podcast') ?></td></tr>
            <tr><td>duration</td><td></td><td><?= __('Duración (HH:MM:SS)') ?></td></tr>
            <tr><td>author</td><td></td><td><?= __('Autor del episodio') ?></td></tr>
          </table>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"<?= __('Episodio de prueba') ?>","description":"<?= __('Descripción') ?>","audio_url":"https://ejemplo.com/ep1.mp3","audio_size_bytes":"5000000","status":"draft"}' \
  "<?= esc($baseUrl) ?>/api/v1/episodes"</pre>
          <pre>{ "success": true, "data": { "id": 5, "title": "<?= __('Episodio de prueba') ?>", ... } }</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/episodes/{id}</code> — <?= __('Actualizar episodio') ?></p>
          <p><?= __('Mismo formato que crear. Solo los campos enviados se modifican.') ?></p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"published"}' \
  "<?= esc($baseUrl) ?>/api/v1/episodes/5"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-delete">DELETE</span> <code class="inline">/api/v1/episodes/{id}</code> — <?= __('Borrar episodio') ?></p>
          <pre>curl -s -X DELETE \
  -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/episodes/5"</pre>
          <pre>{ "success": true, "data": { "deleted": true } }</pre>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="podcast">
        <h2><?= __('Podcast') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/podcast</code> — <?= __('Obtener metadatos del canal') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" "<?= esc($baseUrl) ?>/api/v1/podcast"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/podcast</code> — <?= __('Actualizar metadatos') ?></p>
          <p><?= __('Campos actualizables:') ?> <code class="inline">title, description, link, language, author, owner_name, owner_email, category, explicit, image_url, copyright, itunes_type, rss_item_limit, home_items_per_page, write_audio_metadata, cache_enabled, app_language</code>.<br>
          <?= __('Para subir imagen del canal usa') ?> <code class="inline">multipart/form-data</code> <?= __('con el campo') ?> <code class="inline">image_file</code>.</p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"<?= __('Mi Podcast Actualizado') ?>"}' \
  "<?= esc($baseUrl) ?>/api/v1/podcast"</pre>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="pages">
        <h2><?= __('Páginas') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/pages</code> — <?= __('Lista paginada') ?></p>
          <p><?= __('Mismos parámetros de paginación que episodios.') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" "<?= esc($baseUrl) ?>/api/v1/pages"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/pages/{id}</code> — <?= __('Obtener página') ?></p>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/pages</code> — <?= __('Crear página') ?></p>
          <table class="params-table">
            <tr><th><?= __('Campo') ?></th><th><?= __('Req.') ?></th><th><?= __('Descripción') ?></th></tr>
            <tr><td>title</td><td>✓</td><td><?= __('Título de la página') ?></td></tr>
            <tr><td>slug</td><td>✓</td><td><?= __('Slug URL (minúsculas, números y guiones)') ?></td></tr>
            <tr><td>content</td><td></td><td><?= __('Contenido HTML/Markdown') ?></td></tr>
            <tr><td>parent_id</td><td></td><td><?= __('ID de página padre (para subpáginas)') ?></td></tr>
            <tr><td>sort_order</td><td></td><td><?= __('Orden de aparición (defecto: 0)') ?></td></tr>
            <tr><td>status</td><td></td><td><code class="inline">draft</code> (<?= __('defecto') ?>) <?= __('o') ?> <code class="inline">published</code></td></tr>
          </table>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"<?= __('Sobre nosotros') ?>","slug":"sobre","status":"published"}' \
  "<?= esc($baseUrl) ?>/api/v1/pages"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/pages/{id}</code> — <?= __('Actualizar página') ?></p>
          <p><?= __('Para cambiar el slug, envía también') ?> <code class="inline">current_full_path</code> <?= __('con la ruta actual.') ?></p>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-delete">DELETE</span> <code class="inline">/api/v1/pages/{id}</code> — <?= __('Borrar página') ?></p>
          <p><?= __('Falla si la página tiene subpáginas hijas.') ?></p>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="social">
        <h2><?= __('Redes sociales') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/social</code> — <?= __('Obtener enlaces') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" "<?= esc($baseUrl) ?>/api/v1/social"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/social</code> — <?= __('Actualizar enlaces') ?></p>
          <p><?= __('Campos:') ?> <code class="inline">blog, linkedin, mastodon, x, pixelfed, instagram, youtube, github, bluesky</code>.<br>
          <?= __('Todos opcionales; los no enviados conservan su valor actual. URLs vacías eliminan el enlace.') ?></p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"github":"https://github.com/usuario","mastodon":"https://mastodon.social/@usuario"}' \
  "<?= esc($baseUrl) ?>/api/v1/social"</pre>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="cache">
        <h2><?= __('Caché') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/cache/clear</code> — <?= __('Limpiar caché web e imágenes') ?></p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/cache/clear"</pre>
          <pre>{ "success": true, "data": { "message": "<?= __('Caché borrada correctamente.') ?>" } }</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/cache/regenerate-images</code> — <?= __('Regenerar variantes de imagen') ?></p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/cache/regenerate-images"</pre>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="stats">
        <h2><?= __('Estadísticas') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/stats</code> — <?= __('Estadísticas del podcast') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" "<?= esc($baseUrl) ?>/api/v1/stats"</pre>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="feed">
        <h2><?= __('Feed') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/feed/regenerate</code> — <?= __('Regenerar feed.xml y sitemap.xml') ?></p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/feed/regenerate"</pre>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section" id="system">
        <h2><?= __('Sistema') ?></h2>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/system/version</code> — <?= __('Consultar versión actual y disponible') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/system/version"</pre>
          <pre>{
  "success": true,
  "data": {
    "current_version": "0.9",
    "latest_version": "1.0.0",
    "update_available": true,
    "fetch_error": ""
  }
}</pre>
          <p><?= __('Si no se puede contactar con GitHub, el campo') ?> <code class="inline">fetch_error</code> <?= __('contendrá el motivo; la respuesta sigue siendo 200.') ?></p>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/system/update</code> — <?= __('Actualizar la aplicación') ?></p>
          <p><strong><?= __('Atención:') ?></strong> <?= __('esta operación descarga e instala la última release desde GitHub y es irreversible. Asegúrate de tener una copia de seguridad antes de ejecutarla.') ?></p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/system/update"</pre>
          <pre>{
  "success": true,
  "data": {
    "message": "Actualización completada.",
    "updated_from": "0.9",
    "updated_to": "1.0.0"
  }
}</pre>
          <p><?= __('Códigos de error posibles:') ?> 409 <?= __('(ya en la versión más reciente)') ?>, 503 <?= __('(error al contactar GitHub)') ?>, 500 <?= __('(error durante la instalación)') ?>.</p>
        </div>
      </div>

      <!-- ================================================================ -->
      <div class="api-section">
        <h2><?= __('Códigos de estado HTTP') ?></h2>
        <table class="params-table">
          <tr><th><?= __('Código') ?></th><th><?= __('Descripción') ?></th></tr>
          <tr><td>200</td><td>OK — <?= __('Petición correcta') ?></td></tr>
          <tr><td>201</td><td>Created — <?= __('Recurso creado correctamente') ?></td></tr>
          <tr><td>204</td><td>No Content — <?= __('Respuesta a preflight OPTIONS') ?></td></tr>
          <tr><td>400</td><td>Bad Request — <?= __('Datos inválidos o campos requeridos ausentes') ?></td></tr>
          <tr><td>401</td><td>Unauthorized — <?= __('Token ausente o inválido') ?></td></tr>
          <tr><td>404</td><td>Not Found — <?= __('Recurso no encontrado') ?></td></tr>
          <tr><td>405</td><td>Method Not Allowed — <?= __('Método HTTP no permitido') ?></td></tr>
          <tr><td>500</td><td>Internal Server Error — <?= __('Error del servidor') ?></td></tr>
        </table>
      </div>

      <p style="margin-top:2rem">
        <a href="api_tokens.php" class="btn"><?= __('← Gestionar tokens') ?></a>
      </p>
    </main>
  </div>
</body>
</html>
