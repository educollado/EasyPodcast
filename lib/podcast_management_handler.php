<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/sitemap_builder.php';
require_once __DIR__ . '/csrf.php';

// ---------------------------------------------------------------------------
// Helpers de URL
// ---------------------------------------------------------------------------

/**
 * Resuelve la URL base para construir assets del podcast.
 * Prioriza la URL del formulario (podcast.link); cae en la URL de BD o host actual como fallback.
 */
function resolvePodcastFormBaseUrl(array $form, PDO $pdo): string
{
    $fromForm = extractBaseUrlFromLink((string) ($form['link'] ?? ''));
    if ($fromForm !== null) {
        return $fromForm;
    }

    return resolveBaseUrl($pdo);
}

/**
 * Resuelve la ruta local de un fichero de imagen a partir de su URL relativa o absoluta.
 * Verifica que la ruta resuelta no escape de la raíz del proyecto (protección path traversal).
 * Devuelve null si la ruta no existe como fichero o queda fuera del proyecto.
 */
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

    $projectRoot = realpath(__DIR__ . '/..');
    if ($projectRoot === false) {
        return null;
    }

    if ($candidate[0] === '/') {
        $candidate = $projectRoot . $candidate;
    } else {
        $candidate = $projectRoot . '/' . $candidate;
    }

    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return null;
    }

    if (strpos($real, $projectRoot . DIRECTORY_SEPARATOR) !== 0 && $real !== $projectRoot) {
        return null;
    }

    return $real;
}

// ---------------------------------------------------------------------------
// Helpers de favicon (GD)
// ---------------------------------------------------------------------------

/**
 * Genera un blob PNG redimensionado a $size×$size desde el fichero de imagen fuente.
 * Requiere la extensión GD. Devuelve null si no hay GD, el fichero no existe o GD falla.
 */
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

/**
 * Construye el binario ICO (formato Windows Icon) a partir de blobs PNG indexados por tamaño.
 * Cada clave del array es el tamaño en píxeles; el valor es el blob PNG correspondiente.
 *
 * @param array<int, string> $pngBlobsBySize Mapa tamaño → blob PNG
 */
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

/**
 * Regenera favicon.ico a partir de la imagen del podcast en los tamaños 16, 32 y 48 px.
 * Devuelve false y rellena $warning con el motivo del fallo si no puede generar el ICO.
 */
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
    $targetPath = __DIR__ . '/../favicon.ico';
    if (@file_put_contents($targetPath, $icoBinary) === false) {
        $warning = 'No se pudo escribir favicon.ico en disco.';
        return false;
    }

    return true;
}

// ---------------------------------------------------------------------------
// Función principal
// ---------------------------------------------------------------------------

/**
 * Carga y procesa los datos del panel de gestión del podcast.
 * En POST: guarda los metadatos, regenera feed.xml/sitemap.xml y favicon.ico.
 * En error de BD no recuperable responde HTTP 500 y termina la ejecución.
 *
 * @return array{form:array, error:string, notice:string}
 */
function loadPodcastManagementData(string $dbPath): array
{
    $error  = '';
    $notice = '';

    $form = [
        'title'               => '',
        'description'         => '',
        'link'                => '',
        'language'            => 'es-ES',
        'author'              => '',
        'owner_name'          => '',
        'owner_email'         => '',
        'category'            => '',
        'explicit'            => '0',
        'image_url'           => '',
        'copyright'           => '',
        'itunes_type'         => 'episodic',
        'rss_item_limit'      => '0',
        'home_items_per_page' => '20',
        'write_audio_metadata' => '0',
        'cache_enabled'       => '0',
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
              home_items_per_page INTEGER NOT NULL DEFAULT 20,
              write_audio_metadata INTEGER NOT NULL DEFAULT 0,
              cache_enabled INTEGER NOT NULL DEFAULT 0
            )"
        );

        // La app usa una sola fila de canal; se carga cuando existe.
        $existing = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
        if ($existing) {
            foreach ($form as $key => $value) {
                $form[$key] = (string) ($existing[$key] ?? $value);
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['cache_action'] ?? '') === 'clear_cache') {
            csrf_verify();
            if (clearWebCache()) {
                $notice = 'Caché borrada correctamente.';
            } else {
                $error = 'No se pudo borrar completamente la caché.';
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
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
                if ($key === 'home_items_per_page') {
                    $form[$key] = trim((string) ($_POST[$key] ?? '20'));
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
            } elseif (
                $form['home_items_per_page'] === ''
                || filter_var($form['home_items_per_page'], FILTER_VALIDATE_INT) === false
                || (int) $form['home_items_per_page'] < 1
            ) {
                $error = 'La cantidad de elementos de la portada debe ser un entero mayor o igual que 1.';
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
                            'image/png'  => 'png',
                            'image/gif'  => 'gif',
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
                            $imagesDir = __DIR__ . '/../images';

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

                if ($error === '') {
                    $params = [
                        ':title'                => $form['title'],
                        ':description'          => $form['description'],
                        ':link'                 => $form['link'],
                        ':language'             => $form['language'] !== '' ? $form['language'] : 'es-ES',
                        ':author'               => $form['author'],
                        ':owner_name'           => $form['owner_name'],
                        ':owner_email'          => $form['owner_email'],
                        ':category'             => $form['category'],
                        ':explicit'             => (int) $form['explicit'],
                        ':image_url'            => $form['image_url'],
                        ':copyright'            => $form['copyright'],
                        ':itunes_type'          => $form['itunes_type'],
                        ':rss_item_limit'       => (int) $form['rss_item_limit'],
                        ':home_items_per_page'  => (int) $form['home_items_per_page'],
                        ':write_audio_metadata' => (int) $form['write_audio_metadata'],
                        ':cache_enabled'        => (int) $form['cache_enabled'],
                    ];

                    if ($existing) {
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
                                 home_items_per_page = :home_items_per_page,
                                 write_audio_metadata = :write_audio_metadata,
                                 cache_enabled = :cache_enabled
                             WHERE id = :id'
                        );
                        $params[':id'] = (int) $existing['id'];
                        $stmt->execute($params);
                        $notice = 'Podcast actualizado correctamente.';
                    } else {
                        // Inserción inicial cuando aún no existe fila de podcast (primera configuración).
                        $stmt = $pdo->prepare(
                            'INSERT INTO podcast
                             (title, description, link, language, author, owner_name, owner_email, category, explicit, image_url, copyright, itunes_type, rss_item_limit, home_items_per_page, write_audio_metadata, cache_enabled)
                             VALUES
                             (:title, :description, :link, :language, :author, :owner_name, :owner_email, :category, :explicit, :image_url, :copyright, :itunes_type, :rss_item_limit, :home_items_per_page, :write_audio_metadata, :cache_enabled)'
                        );
                        $stmt->execute($params);
                        $notice = 'Podcast guardado correctamente.';
                    }

                    try {
                        // Mantiene feed.xml/sitemap.xml sincronizados con los últimos metadatos.
                        writePodcastFeedFile($pdo, __DIR__ . '/../feed.xml', resolveFeedSelfHref($pdo));
                        writePodcastSitemapFile($pdo, __DIR__ . '/../sitemap.xml');
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

                    // Recarga el formulario con los datos persistidos.
                    $existing = $pdo->query('SELECT * FROM podcast ORDER BY id ASC LIMIT 1')->fetch();
                    if ($existing) {
                        foreach ($form as $key => $value) {
                            $form[$key] = (string) ($existing[$key] ?? $value);
                        }
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

    return compact('form', 'error', 'notice');
}
