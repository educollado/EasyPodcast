<?php

declare(strict_types=1);

// Panel CRUD de episodios:
// - crear/actualizar/borrar episodios
// - gestionar subidas de audio/imagen
// - regenerar feed.xml automáticamente tras escrituras relevantes
require_once __DIR__ . '/feed_builder.php';
require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/episode_helpers.php';
require_once __DIR__ . '/lib/id3_service.php';

// ---------------------------------------------------------------------------
// Bootstrap de administración
// ---------------------------------------------------------------------------

session_start();

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
$error = '';
$notice = '';
$id3Notice = '';
$editingEpisodeId = null;
$isEditing = false;

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$form = [
    'guid' => '',
    'title' => '',
    'description' => '',
    'link' => '',
    'pub_date' => date('Y-m-d\\TH:i'),
    'audio_url' => '',
    'audio_mime_type' => 'audio/mpeg',
    'audio_size_bytes' => '',
    'duration' => '',
    'explicit' => '',
    'season_number' => '',
    'episode_number' => '',
    'episode_type' => '',
    'image_url' => '',
    'author' => '',
    'status' => 'draft',
];

// ---------------------------------------------------------------------------
// Flujo principal de persistencia (validación + subida + guardado)
// ---------------------------------------------------------------------------

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Asegura que la tabla de episodios exista antes de procesar acciones.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS episodes (
          id INTEGER PRIMARY KEY,
          guid TEXT NOT NULL UNIQUE,
          title TEXT NOT NULL,
          description TEXT NOT NULL,
          link TEXT,
          pub_date TEXT NOT NULL,
          audio_url TEXT NOT NULL,
          audio_mime_type TEXT NOT NULL,
          audio_size_bytes INTEGER NOT NULL,
          duration TEXT,
          explicit INTEGER,
          season_number INTEGER,
          episode_number INTEGER,
          episode_type TEXT,
          image_url TEXT,
          author TEXT,
          status TEXT NOT NULL DEFAULT 'draft',
          created_at TEXT DEFAULT (datetime('now')),
          updated_at TEXT DEFAULT (datetime('now'))
        )"
    );

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_episodes_status_pubdate ON episodes(status, pub_date)");

    // Valores por defecto heredados del podcast cuando hay campos vacíos.
    $podcastDefaults = [
        'title' => '',
        'image_url' => '',
        'author' => '',
        'write_audio_metadata' => 0,
    ];
    $podcastTableExists = (bool) $pdo
        ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'podcast' LIMIT 1")
        ->fetchColumn();
    if ($podcastTableExists) {
        $podcastColumns = $pdo->query('PRAGMA table_info(podcast)')->fetchAll();
        $hasWriteAudioMetadata = false;
        foreach ($podcastColumns as $podcastColumn) {
            if (($podcastColumn['name'] ?? '') === 'write_audio_metadata') {
                $hasWriteAudioMetadata = true;
                break;
            }
        }
        if (!$hasWriteAudioMetadata) {
            $pdo->exec('ALTER TABLE podcast ADD COLUMN write_audio_metadata INTEGER NOT NULL DEFAULT 0');
        }

        $podcastStmt = $pdo->query('SELECT title, image_url, owner_name, write_audio_metadata FROM podcast ORDER BY id ASC LIMIT 1');
        $podcastData = $podcastStmt->fetch();
        if ($podcastData) {
            $podcastDefaults['title'] = trim((string) ($podcastData['title'] ?? ''));
            $podcastDefaults['image_url'] = trim((string) ($podcastData['image_url'] ?? ''));
            $podcastDefaults['author'] = trim((string) ($podcastData['owner_name'] ?? ''));
            $podcastDefaults['write_audio_metadata'] = (int) ($podcastData['write_audio_metadata'] ?? 0);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($podcastDefaults['image_url'] !== '') {
            $form['image_url'] = $podcastDefaults['image_url'];
        }
        if ($podcastDefaults['author'] !== '') {
            $form['author'] = $podcastDefaults['author'];
        }
    }

    // Cargador de modo edición.
    $requestedEpisodeId = (int) ($_GET['episode_id'] ?? 0);
    if ($requestedEpisodeId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $editStmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id LIMIT 1');
        $editStmt->execute([':id' => $requestedEpisodeId]);
        $episodeToEdit = $editStmt->fetch();
        if ($episodeToEdit) {
            $editingEpisodeId = $requestedEpisodeId;
            $isEditing = true;
            foreach ($form as $key => $value) {
                if ($key === 'pub_date') {
                    $form[$key] = formatDateTimeLocal((string) ($episodeToEdit[$key] ?? ''));
                } elseif ($key === 'explicit') {
                    $rawExplicit = $episodeToEdit[$key];
                    $form[$key] = $rawExplicit === null ? '' : (string) ((int) $rawExplicit);
                } else {
                    $form[$key] = trim((string) ($episodeToEdit[$key] ?? ''));
                }
            }
        } else {
            $error = 'No se encontró el capítulo solicitado para editar.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Flujo de creación/actualización.
        $postedEpisodeId = (int) ($_POST['episode_id'] ?? 0);
        if ($postedEpisodeId > 0) {
            $editingEpisodeId = $postedEpisodeId;
            $isEditing = true;
        }

        foreach ($form as $key => $value) {
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        // Botón específico en edición para reescribir tags del MP3 actual.
        $rewriteAudioMetadata = $isEditing && isset($_POST['rewrite_audio_metadata']) && (string) $_POST['rewrite_audio_metadata'] === '1';
        $uploadedNewAudio = false;

        // Bloque principal de validación.
        if (!in_array($form['explicit'], ['', '0', '1'], true)) {
            $error = 'El valor de explícito no es válido.';
        } elseif (!in_array($form['status'], ['draft', 'published'], true)) {
            $error = 'El estado debe ser draft o published.';
        } elseif ($form['episode_type'] !== '' && !in_array($form['episode_type'], ['full', 'trailer', 'bonus'], true)) {
            $error = 'El tipo de episodio debe ser full, trailer o bonus.';
        } elseif ($form['title'] === '' || $form['description'] === '' || $form['pub_date'] === '') {
            $error = 'Título, descripción y fecha de publicación son obligatorios.';
        }

        $pubDateNormalized = normalizeDateTime($form['pub_date']);
        if ($error === '' && $pubDateNormalized === null) {
            $error = 'La fecha de publicación no es válida.';
        }

        $numericFields = [
            'audio_size_bytes' => false,
            'season_number' => true,
            'episode_number' => true,
        ];
        foreach ($numericFields as $field => $allowEmpty) {
            $value = $form[$field];
            if ($value === '' && $allowEmpty) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value) || (int) $value < 0) {
                $error = 'Revisa los campos numéricos: deben ser enteros positivos.';
                break;
            }
        }

        if ($error === '') {
            // Subida opcional de portada del episodio.
            $uploadedImage = $_FILES['image_file'] ?? null;
            if (is_array($uploadedImage) && (int) ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $imageError = (int) ($uploadedImage['error'] ?? UPLOAD_ERR_OK);
                if ($imageError !== UPLOAD_ERR_OK) {
                    $error = 'No se pudo subir la imagen del capítulo.';
                } else {
                    $tmpPath = (string) ($uploadedImage['tmp_name'] ?? '');
                    $originalName = (string) ($uploadedImage['name'] ?? '');
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = (string) $finfo->file($tmpPath);
                    $allowedImages = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                    ];

                    if (!isset($allowedImages[$mimeType])) {
                        $error = 'La imagen debe ser jpg, png, gif o webp.';
                    } else {
                        $fileName = buildSafeFileName($originalName, 'episode-image', $allowedImages[$mimeType]);
                        $imagesDir = __DIR__ . '/images';
                        if (!is_dir($imagesDir) && !mkdir($imagesDir, 0755, true) && !is_dir($imagesDir)) {
                            $error = 'No se pudo crear la carpeta /images.';
                        } elseif (!move_uploaded_file($tmpPath, $imagesDir . '/' . $fileName)) {
                            $error = 'No se pudo guardar la imagen subida.';
                        } else {
                            $form['image_url'] = rtrim(resolveBaseUrl($pdo), '/') . '/images/' . $fileName;
                        }
                    }
                }
            }
        }

        if ($error === '') {
            // Subida opcional de audio. Si existe, sobreescribe URL/MIME/tamaño desde fichero.
            $uploadedAudio = $_FILES['audio_file'] ?? null;
            if (is_array($uploadedAudio) && (int) ($uploadedAudio['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $audioError = (int) ($uploadedAudio['error'] ?? UPLOAD_ERR_OK);
                if ($audioError !== UPLOAD_ERR_OK) {
                    $error = 'No se pudo subir el audio del capítulo.';
                } else {
                    $tmpPath = (string) ($uploadedAudio['tmp_name'] ?? '');
                    $originalName = (string) ($uploadedAudio['name'] ?? '');
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = (string) $finfo->file($tmpPath);
                    $audioExtension = resolveAudioExtension($mimeType, $originalName);

                    if ($audioExtension === null) {
                        $detectedMime = $mimeType !== '' ? $mimeType : 'desconocido';
                        $detectedExtension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
                        if ($detectedExtension === '') {
                            $detectedExtension = 'sin extensión';
                        }
                        $error = 'El audio debe ser mp3, m4a, aac, ogg, wav o webm. MIME detectado: '
                            . $detectedMime . '. Extensión detectada: ' . $detectedExtension . '.';
                    } else {
                        $fileName = buildSafeFileName($originalName, 'episode-audio', $audioExtension);
                        $audiosDir = __DIR__ . '/audios';
                        $targetPath = $audiosDir . '/' . $fileName;

                        if (!is_dir($audiosDir) && !mkdir($audiosDir, 0755, true) && !is_dir($audiosDir)) {
                            $error = 'No se pudo crear la carpeta /audios.';
                        } elseif (!move_uploaded_file($tmpPath, $targetPath)) {
                            $error = 'No se pudo guardar el audio subido. Revisa upload_tmp_dir/open_basedir en PHP.';
                        } else {
                            $uploadedNewAudio = true;
                            if ($audioExtension === 'mp3' && $podcastDefaults['write_audio_metadata'] === 1) {
                                $id3Metadata = buildEpisodeId3Metadata($form, $podcastDefaults);

                                if (!writeMp3Id3Tags($targetPath, $id3Metadata)) {
                                    $id3Notice = 'Aviso: no se pudieron escribir etiquetas ID3 en el MP3 subido.';
                                }
                            }

                            $fileSize = filesize($targetPath);
                            if ($fileSize === false) {
                                $error = 'No se pudo leer el tamaño del audio subido.';
                            } else {
                                $form['audio_url'] = rtrim(resolveBaseUrl($pdo), '/') . '/audios/' . $fileName;
                                $form['audio_mime_type'] = $mimeType !== '' ? $mimeType : 'audio/mpeg';
                                $form['audio_size_bytes'] = (string) $fileSize;
                            }
                        }
                    }
                }
            }
        }

        if ($error === '') {
            if ($form['audio_url'] === '') {
                $error = 'Debes indicar la URL de audio o subir un fichero de audio.';
            } elseif ($form['audio_mime_type'] === '') {
                $error = 'El MIME del audio es obligatorio.';
            } elseif ($form['audio_size_bytes'] === '' || !ctype_digit($form['audio_size_bytes']) || (int) $form['audio_size_bytes'] <= 0) {
                $error = 'El tamaño del audio debe ser un entero mayor que 0.';
            }
        }

        // Valores fallback heredados de la configuración del podcast.
        if ($error === '' && $form['image_url'] === '') {
            if ($podcastDefaults['image_url'] !== '') {
                $form['image_url'] = $podcastDefaults['image_url'];
            }
        }

        if ($error === '' && $form['author'] === '') {
            if ($podcastDefaults['author'] !== '') {
                $form['author'] = $podcastDefaults['author'];
            }
        }

        // Reescribe metadatos cuando:
        // - estamos en edición
        // - no se acaba de subir un audio nuevo
        // - se pulsó el botón manual o la opción global está activa
        $shouldRewriteMetadata = $isEditing
            && !$uploadedNewAudio
            && ($rewriteAudioMetadata || $podcastDefaults['write_audio_metadata'] === 1);

        if ($error === '' && $shouldRewriteMetadata) {
            if ($podcastDefaults['write_audio_metadata'] !== 1) {
                $id3Notice = 'Aviso: activa primero "Escribir metadatos ID3 en MP3 al subir episodio" en Gestión Podcast.';
            } else {
                $existingAudioPath = resolveLocalAudioPathFromUrl($form['audio_url']);
                if ($existingAudioPath === null) {
                    $id3Notice = 'Aviso: no se encontró un MP3 local en /audios/ para actualizar metadatos.';
                } elseif (strtolower((string) pathinfo($existingAudioPath, PATHINFO_EXTENSION)) !== 'mp3') {
                    $id3Notice = 'Aviso: la actualización manual de metadatos solo está disponible para MP3.';
                } else {
                    $hashBefore = hash_file('sha1', $existingAudioPath) ?: null;
                    $id3Metadata = buildEpisodeId3Metadata($form, $podcastDefaults);
                    if (!writeMp3Id3Tags($existingAudioPath, $id3Metadata)) {
                        $id3Notice = 'Aviso: no se pudieron actualizar las etiquetas ID3 del MP3 existente.';
                    } else {
                        $fileSize = filesize($existingAudioPath);
                        if ($fileSize !== false) {
                            $form['audio_size_bytes'] = (string) $fileSize;
                        }
                        $hashAfter = hash_file('sha1', $existingAudioPath) ?: null;
                        if ($hashBefore !== null && $hashAfter !== null && $hashBefore === $hashAfter) {
                            $id3Notice = 'Metadatos ID3 revisados: el MP3 ya tenía esos valores.';
                        } else {
                            $id3Notice = 'Metadatos ID3 actualizados en el MP3 existente.';
                        }
                    }
                }
            }
        }

        // Autogenera el enlace público solo al crear; en edición se respeta el valor actual.
        if ($error === '' && !$isEditing && $form['link'] === '') {
            $form['link'] = buildEpisodePublicLink(resolveBaseUrl($pdo), $pubDateNormalized, $form['title']);
        }

        if ($error === '') {
            if ($form['guid'] === '') {
                $form['guid'] = generateGuid();
            }

            if ($isEditing && $editingEpisodeId !== null) {
                // Actualiza un episodio existente.
                $stmt = $pdo->prepare(
                    'UPDATE episodes
                     SET guid = :guid,
                         title = :title,
                         description = :description,
                         link = :link,
                         pub_date = :pub_date,
                         audio_url = :audio_url,
                         audio_mime_type = :audio_mime_type,
                         audio_size_bytes = :audio_size_bytes,
                         duration = :duration,
                         explicit = :explicit,
                         season_number = :season_number,
                         episode_number = :episode_number,
                         episode_type = :episode_type,
                         image_url = :image_url,
                         author = :author,
                         status = :status,
                         updated_at = datetime(\'now\')
                     WHERE id = :id'
                );
            } else {
                // Inserta un episodio nuevo.
                $stmt = $pdo->prepare(
                    'INSERT INTO episodes
                     (guid, title, description, link, pub_date, audio_url, audio_mime_type, audio_size_bytes, duration, explicit, season_number, episode_number, episode_type, image_url, author, status, updated_at)
                     VALUES
                     (:guid, :title, :description, :link, :pub_date, :audio_url, :audio_mime_type, :audio_size_bytes, :duration, :explicit, :season_number, :episode_number, :episode_type, :image_url, :author, :status, datetime(\'now\'))'
                );
            }

            $params = [
                ':guid' => $form['guid'],
                ':title' => $form['title'],
                ':description' => $form['description'],
                ':link' => $form['link'] !== '' ? $form['link'] : null,
                ':pub_date' => $pubDateNormalized,
                ':audio_url' => $form['audio_url'],
                ':audio_mime_type' => $form['audio_mime_type'],
                ':audio_size_bytes' => (int) $form['audio_size_bytes'],
                ':duration' => $form['duration'] !== '' ? $form['duration'] : null,
                ':explicit' => $form['explicit'] !== '' ? (int) $form['explicit'] : null,
                ':season_number' => $form['season_number'] !== '' ? (int) $form['season_number'] : null,
                ':episode_number' => $form['episode_number'] !== '' ? (int) $form['episode_number'] : null,
                ':episode_type' => $form['episode_type'] !== '' ? $form['episode_type'] : null,
                ':image_url' => $form['image_url'] !== '' ? $form['image_url'] : null,
                ':author' => $form['author'] !== '' ? $form['author'] : null,
                ':status' => $form['status'],
            ];
            if ($isEditing && $editingEpisodeId !== null) {
                $params[':id'] = $editingEpisodeId;
            }
            $stmt->execute($params);

            if ($isEditing && $editingEpisodeId !== null) {
                $notice = 'Capítulo actualizado correctamente.';
            } else {
                $notice = 'Capítulo guardado correctamente.';
                $form = [
                    'guid' => '',
                    'title' => '',
                    'description' => '',
                    'link' => '',
                    'pub_date' => date('Y-m-d\\TH:i'),
                    'audio_url' => '',
                    'audio_mime_type' => 'audio/mpeg',
                    'audio_size_bytes' => '',
                    'duration' => '',
                    'explicit' => '',
                    'season_number' => '',
                    'episode_number' => '',
                    'episode_type' => '',
                    'image_url' => $podcastDefaults['image_url'],
                    'author' => $podcastDefaults['author'],
                    'status' => 'draft',
                ];
            }
            try {
                // Regenera feed.xml después de insertar/actualizar.
                writePodcastFeedFile($pdo, __DIR__ . '/feed.xml', resolveFeedSelfHref($pdo));
            } catch (Throwable $feedError) {
                $notice .= ' (Aviso: no se pudo regenerar el feed.xml)';
            }
            if ($id3Notice !== '') {
                $notice .= ' (' . $id3Notice . ')';
            }
        }

        $form['pub_date'] = formatDateTimeLocal($form['pub_date']);
    }

} catch (Throwable $e) {
    $message = $e->getMessage();
    if (stripos($message, 'UNIQUE constraint failed: episodes.guid') !== false) {
        $error = 'El GUID ya existe. Usa otro valor o déjalo vacío para generarlo automáticamente.';
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en add_episode.php: ' . $message . "\n";
        exit;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEditing ? 'Editar Capítulo' : 'Añadir Capítulo' ?></title>
  <link rel="stylesheet" href="/assets/css/episodes_management.css">
</head>
<body>
  <div class="container">
    <main class="card">
      <h1><?= $isEditing ? 'Editar Capítulo' : 'Añadir Capítulo' ?></h1>
      <p><?= $isEditing ? 'Edita el capítulo seleccionado.' : 'Completa los datos para insertar un episodio en la tabla <strong>episodes</strong>.' ?></p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="add_episode.php<?= $isEditing && $editingEpisodeId !== null ? '?episode_id=' . (int) $editingEpisodeId : '' ?>" autocomplete="off" enctype="multipart/form-data">
        <?php if ($isEditing && $editingEpisodeId !== null): ?>
          <input type="hidden" name="episode_id" value="<?= (int) $editingEpisodeId ?>">
        <?php endif; ?>
        <div class="grid two">
          <label>
            GUID (opcional)
            <input type="text" name="guid" value="<?= esc($form['guid']) ?>" placeholder="Si está vacío se genera automáticamente">
          </label>
          <label>
            Fecha de publicación *
            <input id="pub_date" type="datetime-local" name="pub_date" value="<?= esc($form['pub_date']) ?>" required>
          </label>
        </div>

        <div class="grid two" style="margin-top: .8rem;">
          <label>
            Título *
            <input id="title" type="text" name="title" value="<?= esc($form['title']) ?>" required>
          </label>
          <label>
            URL del capítulo (opcional)
            <input id="episode_link" type="url" name="link" value="<?= esc($form['link']) ?>">
            <button id="generate_link_button" class="small-btn" type="button">Generar URL</button>
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
            Audio (URL)
            <input type="url" name="audio_url" value="<?= esc($form['audio_url']) ?>">
            <span class="help">Si subes audio, esta URL se rellena automáticamente con /audios/fichero.</span>
          </label>
          <label>
            O subir audio del capítulo
            <input id="audio_file" type="file" name="audio_file" accept="audio/*">
          </label>
          <label>
            MIME audio *
            <input id="audio_mime_type" type="text" name="audio_mime_type" value="<?= esc($form['audio_mime_type']) ?>" placeholder="audio/mpeg">
          </label>
          <label>
            Tamaño audio en bytes *
            <input id="audio_size_bytes" type="number" name="audio_size_bytes" min="1" step="1" value="<?= esc($form['audio_size_bytes']) ?>">
          </label>
          <label>
            Duración (HH:MM:SS)
            <input id="duration" type="text" name="duration" value="<?= esc($form['duration']) ?>" placeholder="00:42:10">
          </label>
          <label>
            Explícito
            <select name="explicit">
              <option value="" <?= $form['explicit'] === '' ? 'selected' : '' ?>>Heredar del podcast</option>
              <option value="0" <?= $form['explicit'] === '0' ? 'selected' : '' ?>>No</option>
              <option value="1" <?= $form['explicit'] === '1' ? 'selected' : '' ?>>Sí</option>
            </select>
          </label>
          <label>
            Temporada
            <input type="number" name="season_number" min="0" step="1" value="<?= esc($form['season_number']) ?>">
          </label>
          <label>
            Número de episodio
            <input type="number" name="episode_number" min="0" step="1" value="<?= esc($form['episode_number']) ?>">
          </label>
          <label>
            Tipo de episodio
            <select name="episode_type">
              <option value="" <?= $form['episode_type'] === '' ? 'selected' : '' ?>>Sin definir</option>
              <option value="full" <?= $form['episode_type'] === 'full' ? 'selected' : '' ?>>full</option>
              <option value="trailer" <?= $form['episode_type'] === 'trailer' ? 'selected' : '' ?>>trailer</option>
              <option value="bonus" <?= $form['episode_type'] === 'bonus' ? 'selected' : '' ?>>bonus</option>
            </select>
          </label>
          <label>
            Imagen del capítulo (URL)
            <input type="url" name="image_url" value="<?= esc($form['image_url']) ?>">
            <span class="help">Si subes imagen, esta URL se rellena automáticamente con /images/fichero.</span>
          </label>
          <label>
            O subir imagen del capítulo
            <input type="file" name="image_file" accept="image/*">
          </label>
          <label>
            Autor
            <input type="text" name="author" value="<?= esc($form['author']) ?>">
          </label>
          <label>
            Estado
            <select name="status">
              <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>draft</option>
              <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>published</option>
            </select>
          </label>
        </div>

        <div class="actions">
          <a class="btn back" href="episodes_management.php">Volver a capítulos</a>
          <?php if ($isEditing): ?>
            <button class="btn" type="submit" name="rewrite_audio_metadata" value="1">Actualizar metadatos del MP3 actual</button>
          <?php endif; ?>
          <button class="btn" type="submit"><?= $isEditing ? 'Actualizar capítulo' : 'Guardar capítulo' ?></button>
        </div>
      </form>
    </main>
  </div>
  <script>
    (function () {
      var audioInput = document.getElementById('audio_file');
      var sizeInput = document.getElementById('audio_size_bytes');
      var durationInput = document.getElementById('duration');
      var mimeInput = document.getElementById('audio_mime_type');
      var titleInput = document.getElementById('title');
      var pubDateInput = document.getElementById('pub_date');
      var linkInput = document.getElementById('episode_link');
      var generateLinkButton = document.getElementById('generate_link_button');

      if (!audioInput || !sizeInput || !durationInput) {
        return;
      }

      function formatDuration(totalSeconds) {
        if (!Number.isFinite(totalSeconds) || totalSeconds < 0) {
          return '';
        }

        var s = Math.floor(totalSeconds);
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
      }

      function slugify(value) {
        var normalized = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return normalized
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '') || 'capitulo';
      }

      function buildEpisodeLink() {
        if (!titleInput || !pubDateInput || !linkInput) {
          return;
        }
        var title = (titleInput.value || '').trim();
        if (!title) {
          return;
        }
        var rawDate = (pubDateInput.value || '').trim();
        var date = rawDate ? new Date(rawDate) : new Date();
        if (!Number.isFinite(date.getTime())) {
          date = new Date();
        }
        var year = String(date.getFullYear());
        var month = String(date.getMonth() + 1).padStart(2, '0');
        linkInput.value = window.location.origin + '/' + year + '/' + month + '/' + slugify(title);
      }

      audioInput.addEventListener('change', function () {
        var file = audioInput.files && audioInput.files[0] ? audioInput.files[0] : null;
        if (!file) {
          return;
        }

        sizeInput.value = String(file.size || '');
        if (mimeInput && file.type) {
          mimeInput.value = file.type;
        }

        var objectUrl = URL.createObjectURL(file);
        var probe = new Audio();
        probe.preload = 'metadata';
        probe.src = objectUrl;

        probe.addEventListener('loadedmetadata', function () {
          durationInput.value = formatDuration(probe.duration);
          URL.revokeObjectURL(objectUrl);
        });

        probe.addEventListener('error', function () {
          URL.revokeObjectURL(objectUrl);
        });
      });

      if (generateLinkButton) {
        generateLinkButton.addEventListener('click', buildEpisodeLink);
      }

    })();
  </script>
</body>
</html>
