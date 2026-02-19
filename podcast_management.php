<?php

declare(strict_types=1);

// Panel de gestión de metadatos del podcast (una sola fila de canal).
require_once __DIR__ . '/feed_builder.php';
require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/cache_service.php';
require_once __DIR__ . '/lib/sitemap_builder.php';

session_start();

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
$error = '';
$notice = '';

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Prioriza la URL principal enviada en el formulario para construir assets.
function resolvePodcastFormBaseUrl(array $form, PDO $pdo): string
{
    $fromForm = extractBaseUrlFromLink((string) ($form['link'] ?? ''));
    if ($fromForm !== null) {
        return $fromForm;
    }

    return resolveBaseUrl($pdo);
}

function resolveLocalImagePathFromUrl(string $imageUrl): ?string
{
    $raw = trim($imageUrl);
    if ($raw === '') {
        return null;
    }

    $parsedPath = (string) parse_url($raw, PHP_URL_PATH);
    $candidate = $parsedPath !== '' ? $parsedPath : $raw;

    if ($candidate === '') {
        return null;
    }

    if ($candidate[0] === '/') {
        $candidate = __DIR__ . $candidate;
    } else {
        $candidate = __DIR__ . '/' . $candidate;
    }

    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return null;
    }

    $projectRoot = realpath(__DIR__);
    if ($projectRoot === false) {
        return null;
    }

    if (strpos($real, $projectRoot . DIRECTORY_SEPARATOR) !== 0 && $real !== $projectRoot) {
        return null;
    }

    return $real;
}

function createPngBlobForIco(string $sourcePath, int $size): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        return null;
    }

    $raw = @file_get_contents($sourcePath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $src = @imagecreatefromstring($raw);
    if ($src === false) {
        return null;
    }

    $dst = imagecreatetruecolor($size, $size);
    if ($dst === false) {
        imagedestroy($src);
        return null;
    }

    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $srcW, $srcH);

    ob_start();
    imagepng($dst, null, 9);
    $pngData = ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    if (!is_string($pngData) || $pngData === '') {
        return null;
    }

    return $pngData;
}

function buildIcoBinaryFromPngBlobs(array $pngBlobsBySize): string
{
    $count = count($pngBlobsBySize);
    $header = pack('vvv', 0, 1, $count);
    $entries = '';
    $images = '';
    $offset = 6 + (16 * $count);

    foreach ($pngBlobsBySize as $size => $blob) {
        $iconSize = (int) $size;
        $sizeByte = $iconSize >= 256 ? 0 : $iconSize;
        $length = strlen($blob);
        $entries .= pack('CCCCvvVV', $sizeByte, $sizeByte, 0, 0, 1, 32, $length, $offset);
        $images .= $blob;
        $offset += $length;
    }

    return $header . $entries . $images;
}

function regeneratePodcastFavicon(string $imageUrl, string &$warning): bool
{
    $warning = '';

    if ($imageUrl === '') {
        return true;
    }
    if (!function_exists('imagecreatefromstring')) {
        $warning = 'No se pudo generar favicon.ico (falta extension GD).';
        return false;
    }

    $sourcePath = resolveLocalImagePathFromUrl($imageUrl);
    if ($sourcePath === null) {
        $warning = 'No se pudo generar favicon.ico (imagen del podcast no localizada en servidor).';
        return false;
    }

    $sizes = [16, 32, 48];
    $blobs = [];
    foreach ($sizes as $size) {
        $blob = createPngBlobForIco($sourcePath, $size);
        if ($blob === null) {
            $warning = 'No se pudo generar favicon.ico (fallo al convertir imagen).';
            return false;
        }
        $blobs[$size] = $blob;
    }

    $icoBinary = buildIcoBinaryFromPngBlobs($blobs);
    $targetPath = __DIR__ . '/favicon.ico';
    if (@file_put_contents($targetPath, $icoBinary) === false) {
        $warning = 'No se pudo escribir favicon.ico en disco.';
        return false;
    }

    return true;
}

$form = [
    'title' => '',
    'description' => '',
    'link' => '',
    'language' => 'es-ES',
    'author' => '',
    'owner_name' => '',
    'owner_email' => '',
    'category' => '',
    'explicit' => '0',
    'image_url' => '',
    'copyright' => '',
    'itunes_type' => 'episodic',
    'rss_item_limit' => '0',
    'write_audio_metadata' => '0',
    'cache_enabled' => '0',
];

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Asegura que exista la tabla de canal antes de renderizar/editar.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS podcast (
          id INTEGER PRIMARY KEY,
          title TEXT NOT NULL,
          description TEXT NOT NULL,
          link TEXT NOT NULL,
          language TEXT NOT NULL DEFAULT 'es-ES',
          author TEXT,
          owner_name TEXT,
          owner_email TEXT,
          category TEXT,
          explicit INTEGER NOT NULL DEFAULT 0,
          image_url TEXT,
          copyright TEXT,
          itunes_type TEXT DEFAULT 'episodic',
          rss_item_limit INTEGER NOT NULL DEFAULT 0,
          write_audio_metadata INTEGER NOT NULL DEFAULT 0,
          cache_enabled INTEGER NOT NULL DEFAULT 0
        )"
    );
    // Migraciones ligeras de columnas en instalaciones existentes.
    $columns = $pdo->query('PRAGMA table_info(podcast)')->fetchAll();
    $hasRssItemLimit = false;
    $hasWriteAudioMetadata = false;
    $hasCacheEnabled = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'rss_item_limit') {
            $hasRssItemLimit = true;
        }
        if (($column['name'] ?? '') === 'write_audio_metadata') {
            $hasWriteAudioMetadata = true;
        }
        if (($column['name'] ?? '') === 'cache_enabled') {
            $hasCacheEnabled = true;
        }
    }
    if (!$hasRssItemLimit) {
        $pdo->exec('ALTER TABLE podcast ADD COLUMN rss_item_limit INTEGER NOT NULL DEFAULT 0');
    }
    if (!$hasWriteAudioMetadata) {
        $pdo->exec('ALTER TABLE podcast ADD COLUMN write_audio_metadata INTEGER NOT NULL DEFAULT 0');
    }
    if (!$hasCacheEnabled) {
        $pdo->exec('ALTER TABLE podcast ADD COLUMN cache_enabled INTEGER NOT NULL DEFAULT 0');
    }

    // La app usa una sola fila de canal; se carga cuando existe.
    $existing = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
    if ($existing) {
        foreach ($form as $key => $value) {
            $form[$key] = (string) ($existing[$key] ?? $value);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['cache_action'] ?? '') === 'clear_cache') {
        if (clearWebCache()) {
            $notice = 'Caché borrada correctamente.';
        } else {
            $error = 'No se pudo borrar completamente la caché.';
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Hidrata el formulario con POST para preservar datos si hay errores de validación.
        foreach ($form as $key => $value) {
            if ($key === 'explicit') {
                $form[$key] = (string) ((int) ($_POST[$key] ?? 0));
                continue;
            }
            if ($key === 'rss_item_limit') {
                $form[$key] = trim((string) ($_POST[$key] ?? '0'));
                continue;
            }
            if ($key === 'write_audio_metadata') {
                $form[$key] = isset($_POST[$key]) ? '1' : '0';
                continue;
            }
            if ($key === 'cache_enabled') {
                $form[$key] = isset($_POST[$key]) ? '1' : '0';
                continue;
            }
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        if ($form['title'] === '' || $form['description'] === '' || $form['link'] === '') {
            $error = 'Título, descripción y enlace son obligatorios.';
        } elseif (
            $form['rss_item_limit'] === ''
            || filter_var($form['rss_item_limit'], FILTER_VALIDATE_INT) === false
            || (int) $form['rss_item_limit'] < 0
        ) {
            $error = 'La cantidad de elementos del feed debe ser un entero igual o mayor que 0.';
        } elseif (!in_array($form['itunes_type'], ['episodic', 'serial'], true)) {
            $error = 'El tipo de podcast debe ser episodic o serial.';
        } elseif ($form['owner_email'] !== '' && !filter_var($form['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'El email del propietario no es válido.';
        } else {
            // Subida opcional de imagen con whitelist MIME y sufijo aleatorio en nombre.
            $uploadedImage = $_FILES['image_file'] ?? null;
            if (is_array($uploadedImage) && (int) ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploadError = (int) ($uploadedImage['error'] ?? UPLOAD_ERR_OK);
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $error = 'No se pudo subir la imagen del podcast.';
                } else {
                    $tmpPath = (string) ($uploadedImage['tmp_name'] ?? '');
                    $originalName = (string) ($uploadedImage['name'] ?? '');
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = (string) $finfo->file($tmpPath);
                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                    ];

                    if (!isset($allowedTypes[$mimeType])) {
                        $error = 'El fichero debe ser una imagen válida (jpg, png, gif o webp).';
                    } else {
                        $safeBaseName = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)));
                        $safeBaseName = trim($safeBaseName, '-');
                        if ($safeBaseName === '') {
                            $safeBaseName = 'podcast-image';
                        }

                        $extension = $allowedTypes[$mimeType];
                        $fileName = $safeBaseName . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
                        $imagesDir = __DIR__ . '/images';

                        if (!is_dir($imagesDir) && !mkdir($imagesDir, 0755, true) && !is_dir($imagesDir)) {
                            $error = 'No se pudo crear la carpeta de imágenes.';
                        } elseif (!move_uploaded_file($tmpPath, $imagesDir . '/' . $fileName)) {
                            $error = 'No se pudo guardar la imagen subida.';
                        } else {
                            $form['image_url'] = rtrim(resolvePodcastFormBaseUrl($form, $pdo), '/') . '/images/' . $fileName;
                        }
                    }
                }
            }

            if ($error !== '') {
                // Mantiene valores del formulario y muestra error sin persistir cambios.
            } elseif ($existing) {
                // Actualiza la fila única del podcast.
                $stmt = $pdo->prepare(
                    'UPDATE podcast
                     SET title = :title,
                         description = :description,
                         link = :link,
                         language = :language,
                         author = :author,
                         owner_name = :owner_name,
                         owner_email = :owner_email,
                         category = :category,
                         explicit = :explicit,
                         image_url = :image_url,
                         copyright = :copyright,
                         itunes_type = :itunes_type,
                         rss_item_limit = :rss_item_limit,
                         write_audio_metadata = :write_audio_metadata,
                         cache_enabled = :cache_enabled
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':title' => $form['title'],
                    ':description' => $form['description'],
                    ':link' => $form['link'],
                    ':language' => $form['language'] !== '' ? $form['language'] : 'es-ES',
                    ':author' => $form['author'],
                    ':owner_name' => $form['owner_name'],
                    ':owner_email' => $form['owner_email'],
                    ':category' => $form['category'],
                    ':explicit' => (int) $form['explicit'],
                    ':image_url' => $form['image_url'],
                    ':copyright' => $form['copyright'],
                    ':itunes_type' => $form['itunes_type'],
                    ':rss_item_limit' => (int) $form['rss_item_limit'],
                    ':write_audio_metadata' => (int) $form['write_audio_metadata'],
                    ':cache_enabled' => (int) $form['cache_enabled'],
                    ':id' => (int) $existing['id'],
                ]);
                $notice = 'Podcast actualizado correctamente.';
                try {
                    // Mantiene feed.xml/sitemap.xml sincronizados con los últimos metadatos.
                    writePodcastFeedFile($pdo, __DIR__ . '/feed.xml', resolveFeedSelfHref($pdo));
                    writePodcastSitemapFile($pdo, __DIR__ . '/sitemap.xml');
                } catch (Throwable $feedError) {
                    $notice .= ' (Aviso: no se pudo regenerar feed.xml/sitemap.xml)';
                }
                $faviconWarning = '';
                if (!regeneratePodcastFavicon((string) $form['image_url'], $faviconWarning) && $faviconWarning !== '') {
                    $notice .= ' (Aviso: ' . $faviconWarning . ')';
                }
                if (!clearWebCache()) {
                    $notice .= ' (Aviso: no se pudo limpiar completamente la caché)';
                }
            } else {
                // Inserción inicial cuando aún no existe fila de podcast (primera configuración).
                $stmt = $pdo->prepare(
                    'INSERT INTO podcast
                     (title, description, link, language, author, owner_name, owner_email, category, explicit, image_url, copyright, itunes_type, rss_item_limit, write_audio_metadata, cache_enabled)
                     VALUES
                     (:title, :description, :link, :language, :author, :owner_name, :owner_email, :category, :explicit, :image_url, :copyright, :itunes_type, :rss_item_limit, :write_audio_metadata, :cache_enabled)'
                );
                $stmt->execute([
                    ':title' => $form['title'],
                    ':description' => $form['description'],
                    ':link' => $form['link'],
                    ':language' => $form['language'] !== '' ? $form['language'] : 'es-ES',
                    ':author' => $form['author'],
                    ':owner_name' => $form['owner_name'],
                    ':owner_email' => $form['owner_email'],
                    ':category' => $form['category'],
                    ':explicit' => (int) $form['explicit'],
                    ':image_url' => $form['image_url'],
                    ':copyright' => $form['copyright'],
                    ':itunes_type' => $form['itunes_type'],
                    ':rss_item_limit' => (int) $form['rss_item_limit'],
                    ':write_audio_metadata' => (int) $form['write_audio_metadata'],
                    ':cache_enabled' => (int) $form['cache_enabled'],
                ]);
                $notice = 'Podcast guardado correctamente.';
                try {
                    // Genera feed.xml/sitemap.xml inmediatamente tras la creación inicial.
                    writePodcastFeedFile($pdo, __DIR__ . '/feed.xml', resolveFeedSelfHref($pdo));
                    writePodcastSitemapFile($pdo, __DIR__ . '/sitemap.xml');
                } catch (Throwable $feedError) {
                    $notice .= ' (Aviso: no se pudo regenerar feed.xml/sitemap.xml)';
                }
                $faviconWarning = '';
                if (!regeneratePodcastFavicon((string) $form['image_url'], $faviconWarning) && $faviconWarning !== '') {
                    $notice .= ' (Aviso: ' . $faviconWarning . ')';
                }
                if (!clearWebCache()) {
                    $notice .= ' (Aviso: no se pudo limpiar completamente la caché)';
                }
            }

            $existing = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
            if ($existing) {
                foreach ($form as $key => $value) {
                    $form[$key] = (string) ($existing[$key] ?? $value);
                }
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error en podcast_management.php: ' . $e->getMessage() . "\n";
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestión Podcast</title>
  <link rel="stylesheet" href="/assets/css/podcast_management.css">
</head>
<body>
  <div class="container">
    <main class="card">
      <h1>Gestión Podcast</h1>
      <p>Completa los metadatos del canal para rellenar la tabla <strong>podcast</strong>.</p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="podcast_management.php" autocomplete="off" enctype="multipart/form-data">
        <div class="grid two">
          <label>
            Título *
            <input type="text" name="title" value="<?= esc($form['title']) ?>" required>
          </label>
          <label>
            URL principal *
            <input type="url" name="link" value="<?= esc($form['link']) ?>" required>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            Descripción *
            <textarea name="description" required><?= esc($form['description']) ?></textarea>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label>
            Idioma
            <input type="text" name="language" value="<?= esc($form['language']) ?>" placeholder="es-ES">
          </label>
          <label>
            Autor (itunes:author)
            <input type="text" name="author" value="<?= esc($form['author']) ?>">
          </label>
          <label>
            Owner name
            <input type="text" name="owner_name" value="<?= esc($form['owner_name']) ?>">
          </label>
          <label>
            Owner email
            <input type="email" name="owner_email" value="<?= esc($form['owner_email']) ?>">
          </label>
          <label>
            Categorías (separadas por coma)
            <input type="text" name="category" value="<?= esc($form['category']) ?>" placeholder="Technology, Education">
          </label>
          <label>
            Explícito
            <select name="explicit">
              <option value="0" <?= $form['explicit'] === '0' ? 'selected' : '' ?>>No</option>
              <option value="1" <?= $form['explicit'] === '1' ? 'selected' : '' ?>>Sí</option>
            </select>
          </label>
          <label>
            Imagen del podcast (URL)
            <input type="url" name="image_url" value="<?= esc($form['image_url']) ?>">
          </label>
          <label>
            O subir imagen del podcast
            <input type="file" name="image_file" accept="image/*">
          </label>
          <label>
            Tipo iTunes
            <select name="itunes_type">
              <option value="episodic" <?= $form['itunes_type'] === 'episodic' ? 'selected' : '' ?>>episodic</option>
              <option value="serial" <?= $form['itunes_type'] === 'serial' ? 'selected' : '' ?>>serial</option>
            </select>
          </label>
          <label>
            Cantidad de elementos del Feed RSS
            <input type="number" min="0" step="1" name="rss_item_limit" value="<?= esc($form['rss_item_limit']) ?>">
            <small>Nota: 0 significa infinitos (sin límite).</small>
          </label>
          <label class="inline-checkbox">
            <input type="checkbox" name="write_audio_metadata" value="1" <?= $form['write_audio_metadata'] === '1' ? 'checked' : '' ?>>
            <span>Escribir metadatos ID3 en MP3 al subir episodio</span>
            <small>Usa datos del episodio/podcast para título, artista, álbum, fecha, comentario y pista.</small>
          </label>
          <label class="inline-checkbox">
            <input type="checkbox" name="cache_enabled" value="1" <?= $form['cache_enabled'] === '1' ? 'checked' : '' ?>>
            <span>Habilitar caché pública en /cache</span>
            <small>Aplica a portada, episodio, feed y sitemap.</small>
          </label>
        </div>

        <div class="grid" style="margin-top: .8rem;">
          <label>
            Copyright
            <input type="text" name="copyright" value="<?= esc($form['copyright']) ?>">
          </label>
        </div>

        <div class="actions">
          <a class="btn back" href="admin.php">Volver al panel</a>
          <button class="btn" type="submit">Guardar podcast</button>
        </div>
      </form>
      <form method="post" action="podcast_management.php" style="margin-top: .8rem;">
        <input type="hidden" name="cache_action" value="clear_cache">
        <div class="actions">
          <button class="btn" type="submit">Borrar caché</button>
        </div>
      </form>
    </main>
  </div>
</body>
</html>
