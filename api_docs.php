<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view_helpers.php';

startSecureSession();
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
$apiDocsPdo = openPodcastDatabase($dbPath);
$baseUrl .= podcastBasePath(activePodcast($apiDocsPdo) ?? [], multipodcastEnabled($apiDocsPdo));
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc(__('Documentación API REST')) ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
</head>
<body>
  <?php $currentAdminPage = 'api_docs'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Documentación API REST') ?></h1>
      <?php if (activePodcast($apiDocsPdo) !== null): ?><p class="muted"><strong><?= __('Podcast') ?>:</strong> <?= esc((string) activePodcast($apiDocsPdo)['title']) ?></p><?php endif; ?>
      <p><?= __('La API REST permite gestionar el podcast de forma programática. Todas las peticiones requieren autenticación.') ?></p>

      <h2><?= __('Autenticación') ?></h2>
      <p><?= __('Incluye tu token en la cabecera') ?> <code class="inline">Authorization</code>:</p>
      <pre>Authorization: Bearer &lt;tu-token&gt;</pre>
      <p><?= __('Gestiona tus tokens en') ?> <a href="api_tokens.php"><?= __('Tokens de API') ?></a>.</p>
      <p><?= __('Cada token hereda los permisos de su usuario. Los usuarios de podcast solo pueden usarlo en los podcasts que tienen asignados; el administrador global puede usar un token administrativo en cualquier podcast indicando su directorio en la URL.') ?></p>
      <p><?= __('Los tokens con alcance') ?> <code class="inline">content</code> <?= __('cubren la API de contenidos y mantenimiento habitual. El endpoint') ?> <code class="inline">/api/v1/system/update</code> <?= __('requiere un token con alcance') ?> <code class="inline">admin</code>.</p>

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
        <a href="#users"><?= __('Usuarios') ?></a>
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
            <tr><td>status</td><td>string</td><td><code class="inline">draft</code>, <code class="inline">scheduled</code> <?= __('o') ?> <code class="inline">published</code> (<?= __('opcional') ?>)</td></tr>
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
            <tr><td>content</td><td>✓</td><td><?= __('Contenido HTML del episodio (máx. 10000 caracteres)') ?></td></tr>
            <tr><td>description</td><td></td><td><?= __('Descripción en texto plano (máx. 4000 caracteres). Se muestra en portada y en el feed RSS.') ?></td></tr>
            <tr><td>audio_url</td><td>✓*</td><td><?= __('URL del audio (*o subir audio_file)') ?></td></tr>
            <tr><td>audio_size_bytes</td><td>✓*</td><td><?= __('Tamaño en bytes (*si no se sube fichero)') ?></td></tr>
            <tr><td>audio_mime_type</td><td></td><td><?= __('MIME del audio (defecto: audio/mpeg)') ?></td></tr>
            <tr><td>status</td><td></td><td><code class="inline">draft</code> (<?= __('defecto') ?>), <code class="inline">scheduled</code> <?= __('o') ?> <code class="inline">published</code></td></tr>
            <tr><td>pub_date</td><td></td><td><?= __('Fecha ISO 8601 (auto-asignada al publicar; obligatoria para scheduled)') ?></td></tr>
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
  -d '{"title":"<?= __('Episodio de prueba') ?>","content":"<?= __('Contenido del episodio') ?>","audio_url":"https://ejemplo.com/ep1.mp3","audio_size_bytes":"5000000","status":"draft"}' \
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
          <p><?= __('Campos actualizables:') ?> <code class="inline">title, description, link, language, author, owner_name, owner_email, category, explicit, image_url, hero_image_url, copyright, itunes_type, rss_item_limit, home_items_per_page, write_audio_metadata, cache_enabled, app_language</code>.<br>
          <?= __('Para subir imágenes del canal usa') ?> <code class="inline">multipart/form-data</code> <?= __('con los campos') ?> <code class="inline">image_file</code> <?= __('y') ?> <code class="inline">hero_image_file</code>.</p>
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
            <tr><td>content</td><td></td><td><?= __('Contenido HTML de la página') ?></td></tr>
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
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/stats</code> — <?= __('Resumen general, caché y estadísticas de descargas') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" "<?= esc($baseUrl) ?>/api/v1/stats"
curl -s -H "Authorization: Bearer TOKEN" "<?= esc($baseUrl) ?>/api/v1/stats?year=2026"</pre>
          <pre>{
  "success": true,
  "data": {
    "episodes": {
      "published": 12,
      "drafts": 3,
      "total": 15,
      "last_title": "Episodio 12",
      "last_pub_date": "2026-04-20 09:30:00",
      "audio_size_bytes": 123456789,
      "audio_size_human": "117.7 MB"
    },
    "cache": {
      "enabled": true,
      "files": 24,
      "size_bytes": 34567,
      "size_human": "33.8 KB"
    },
    "downloads": {
      "filter_year": 2026,
      "available_years": [2026, 2025],
      "daily": {
        "items": [
          {
            "episode_title": "Episodio 12",
            "action_type": "feed",
            "action_type_label": "Feed",
            "ip_address": "203.0.113.10",
            "display_date": "22/04/2026 11:00:00"
          }
        ],
        "total": 1
      },
      "monthly": {
        "items": [{ "period_label": "Abr 2026", "descargas": 10 }],
        "total": 1
      },
      "yearly": { "items": [], "total": 0 },
      "summary": { "items": [], "total": 0 }
    }
  }
}</pre>
          <p><?= __('Incluye estas claves principales:') ?></p>
          <ul>
            <li><code class="inline">episodes</code>: <?= __('resumen general de episodios y tamaño total de audio.') ?></li>
            <li><code class="inline">cache</code>: <?= __('estado de la caché, número de ficheros y tamaño total.') ?></li>
            <li><code class="inline">downloads.daily</code>: <?= __('últimos registros diarios con tipo de acción, IP, user agent y referer.') ?></li>
            <li><code class="inline">downloads.monthly</code>: <?= __('agregados mensuales por episodio; admite el filtro opcional year.') ?></li>
            <li><code class="inline">downloads.yearly</code>: <?= __('agregados anuales por episodio.') ?></li>
            <li><code class="inline">downloads.summary</code>: <?= __('totales acumulados por episodio con descargas o reproducciones.') ?></li>
          </ul>
          <p><?= __('También se incluyen campos auxiliares de presentación como audio_size_human, size_human, display_date, action_type_label y period_label.') ?></p>
          <p><?= __('Se mantienen las claves anteriores de episodes y cache por compatibilidad.') ?></p>
          <p><?= __('El parámetro opcional') ?> <code class="inline">year</code> <?= __('filtra solo la colección mensual; el resto de bloques se devuelve completo.') ?></p>
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
      <div class="api-section" id="users">
        <h2><?= __('Usuarios') ?></h2>
        <p><?= __('La gestión de usuarios exige un token admin del administrador global. Los tokens de usuarios de podcast no pueden usar estos endpoints.') ?></p>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/users/podcasts</code> — <?= __('Listar podcasts asignables') ?></p>
          <pre>curl -s -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/users/podcasts"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/users</code> — <?= __('Listar usuarios') ?></p>
          <p><span class="method method-get">GET</span> <code class="inline">/api/v1/users/{id}</code> — <?= __('Obtener usuario') ?></p>
          <p><?= __('Las respuestas incluyen los podcasts asignados, pero nunca incluyen contraseñas.') ?></p>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/users</code> — <?= __('Crear usuario y asignar podcasts') ?></p>
          <table class="params-table">
            <tr><th><?= __('Campo') ?></th><th><?= __('Req.') ?></th><th><?= __('Descripción') ?></th></tr>
            <tr><td>first_name</td><td>✓</td><td><?= __('Nombre') ?></td></tr>
            <tr><td>last_name</td><td>✓</td><td><?= __('Apellidos') ?></td></tr>
            <tr><td>email</td><td>✓</td><td><?= __('Email') ?></td></tr>
            <tr><td>password</td><td>✓</td><td><?= __('Mínimo 8 caracteres') ?></td></tr>
            <tr><td>is_active</td><td></td><td><code class="inline">true</code> <?= __('o') ?> <code class="inline">false</code> (<?= __('defecto') ?>: <code class="inline">true</code>)</td></tr>
            <tr><td>podcast_ids</td><td>✓*</td><td><?= __('Lista de IDs de podcasts') ?></td></tr>
            <tr><td>podcast_slugs</td><td>✓*</td><td><?= __('Lista de directorios de podcasts; puede usarse en lugar de podcast_ids') ?></td></tr>
          </table>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"first_name":"Ana","last_name":"García","email":"ana@example.com","password":"contraseña-segura","podcast_slugs":["podcast-uno","podcast-dos"],"is_active":true}' \
  "<?= esc($baseUrl) ?>/api/v1/users"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-post">POST</span> <code class="inline">/api/v1/users/{id}</code> — <?= __('Actualizar usuario y sus asignaciones') ?></p>
          <p><?= __('Solo se modifican los campos enviados. Una contraseña vacía conserva la contraseña actual; las listas de podcasts sustituyen todas las asignaciones anteriores.') ?></p>
          <pre>curl -s -X POST \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"podcast_ids":[1,3],"is_active":true}' \
  "<?= esc($baseUrl) ?>/api/v1/users/5"</pre>
        </div>

        <div class="endpoint-block">
          <p><span class="method method-delete">DELETE</span> <code class="inline">/api/v1/users/{id}</code> — <?= __('Borrar usuario y sus tokens API') ?></p>
          <pre>curl -s -X DELETE \
  -H "Authorization: Bearer TOKEN" \
  "<?= esc($baseUrl) ?>/api/v1/users/5"</pre>
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
          <p><?= __('Este endpoint exige un token con alcance') ?> <code class="inline">admin</code>.</p>
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
          <tr><td>403</td><td>Forbidden — <?= __('El token autenticado no tiene permisos suficientes') ?></td></tr>
          <tr><td>404</td><td>Not Found — <?= __('Recurso no encontrado') ?></td></tr>
          <tr><td>405</td><td>Method Not Allowed — <?= __('Método HTTP no permitido') ?></td></tr>
          <tr><td>500</td><td>Internal Server Error — <?= __('Error del servidor') ?></td></tr>
        </table>
      </div>

      <p class="section-gap-xl">
        <a href="api_tokens.php" class="btn"><?= __('← Gestionar tokens') ?></a>
      </p>
    </main>
  </div>
</body>
</html>
