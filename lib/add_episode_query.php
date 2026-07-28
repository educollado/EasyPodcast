<?php

declare(strict_types=1);

require_once __DIR__ . '/episode_helpers.php';
require_once __DIR__ . '/episode_save_handler.php';
require_once __DIR__ . '/csrf.php';

/**
 * Prepara todos los datos del formulario de añadir/editar episodio.
 * - GET con episode_id: carga el episodio en el formulario (modo edición).
 * - POST: guarda el episodio y devuelve el estado actualizado del formulario.
 * En error de BD no recuperable responde HTTP 500 y termina la ejecución.
 *
 * @return array{form:array, isEditing:bool, editingEpisodeId:?int, error:string, notice:string}
 */
function loadAddEpisodeData(string $dbPath): array
{
    $error            = '';
    $notice           = '';
    $editingEpisodeId = null;
    $isEditing        = false;

    // Defaults seguros para que el template siempre tenga variables definidas,
    // incluso si la conexión a la BD falla antes de cargar los datos reales.
    $podcastDefaults = ['title' => '', 'image_url' => '', 'author' => '', 'write_audio_metadata' => 0];
    $form = episodeFormDefaults($podcastDefaults);

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
              content TEXT NOT NULL,
              short_description TEXT,
              link TEXT,
              pub_date TEXT,
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

        $podcastDefaults = loadPodcastDefaults($pdo);
        $form = episodeFormDefaults($podcastDefaults);

        // Modo edición: carga los datos del episodio en el formulario (solo en GET).
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

        // Autorrellenar campos al crear un episodio nuevo (GET sin episode_id).
        // Carga el último episodio guardado (por pub_date DESC, id DESC) y:
        //   - season_number  → misma temporada que el último episodio.
        //   - episode_number → número del último + 1 (siguiente de la serie).
        //   - episode_type   → mismo tipo (full, trailer, bonus…) que el último.
        // En modo edición o POST este bloque no se ejecuta, para no pisar los
        // valores reales del episodio que se está modificando.
        if (!$isEditing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $lastStmt = $pdo->query(
                'SELECT season_number, episode_number, episode_type FROM episodes ORDER BY datetime(pub_date) DESC, id DESC LIMIT 1'
            );
            $lastEpisode = $lastStmt ? $lastStmt->fetch() : false;
            if ($lastEpisode) {
                $form['season_number'] = (string) ((int) ($lastEpisode['season_number'] ?? 0));
                $form['episode_number'] = (string) ((int) ($lastEpisode['episode_number'] ?? 0) + 1);
                $form['episode_type']   = (string) ($lastEpisode['episode_type'] ?? '');
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $postedEpisodeId = (int) ($_POST['episode_id'] ?? 0);
            if ($postedEpisodeId > 0) {
                $editingEpisodeId = $postedEpisodeId;
                $isEditing = true;
            }
            // Iteramos sobre las claves de $form (no sobre $_POST) para no aceptar
            // campos arbitrarios que el cliente pudiera añadir de forma maliciosa.
            foreach ($form as $key => $_) {
                $form[$key] = trim((string) ($_POST[$key] ?? ''));
            }
            // El formulario presenta el tamaño en MB, pero la BD y el enclosure
            // RSS deben conservarlo como un entero de bytes.
            $postedMegabytes = trim((string) ($_POST['audio_size_mb'] ?? ''));
            $postedBytes = trim((string) ($_POST['audio_size_bytes'] ?? ''));
            $form['audio_size_bytes'] = $postedMegabytes === audioBytesToMegabytesForInput($postedBytes)
                ? $postedBytes
                : audioMegabytesToBytes($postedMegabytes);
            // rewrite_audio_metadata solo tiene sentido en edición; en creación se ignora.
            $rewriteAudioMetadata = $isEditing && ($_POST['rewrite_audio_metadata'] ?? '') === '1';

            $result = saveEpisode($pdo, $form, $isEditing, $editingEpisodeId, $podcastDefaults, $_FILES, $rewriteAudioMetadata);
            $form   = $result['form'];
            $error  = $result['error'];
            $notice = $result['notice'];
            // Convierte pub_date al formato datetime-local para pre-rellenar el campo.
            $form['pub_date'] = formatDateTimeLocal($form['pub_date']);
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'UNIQUE constraint failed: episodes.guid') !== false) {
            // Error recuperable: muestra mensaje en el formulario sin abortar.
            $error = 'El GUID ya existe. Usa otro valor o déjalo vacío para generarlo automáticamente.';
        } else {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Error en add_episode.php: ' . $message . "\n";
            exit;
        }
    }

    return compact('form', 'isEditing', 'editingEpisodeId', 'error', 'notice');
}
