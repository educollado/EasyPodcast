<?php

declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/cache_service.php';

/** Campos de la tabla social en el orden en que se muestran. */
const SOCIAL_FIELDS = ['blog', 'linkedin', 'mastodon', 'x', 'pixelfed', 'instagram', 'youtube', 'github', 'bluesky'];

/**
 * Devuelve los enlaces de redes sociales configurados, o un array vacío si no hay
 * tabla o hay error de BD. Usado por header.php para renderizar los iconos.
 *
 * @return array<string,string>
 */
function getSocialLinks(string $dbPath): array
{
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $exists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='social' LIMIT 1")
            ->fetchColumn();
        if (!$exists) {
            return [];
        }

        $row = $pdo->query('SELECT * FROM social ORDER BY id ASC LIMIT 1')->fetch();
        return is_array($row) ? $row : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Carga y procesa los datos del panel de redes sociales.
 * En POST guarda los enlaces y borra la caché web.
 *
 * @return array{form:array<string,string>, error:string, notice:string}
 */
function loadSocialData(string $dbPath): array
{
    $error  = '';
    $notice = '';

    $form = array_fill_keys(SOCIAL_FIELDS, '');

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS social (
              id INTEGER PRIMARY KEY,
              blog      TEXT NOT NULL DEFAULT '',
              linkedin  TEXT NOT NULL DEFAULT '',
              mastodon  TEXT NOT NULL DEFAULT '',
              x         TEXT NOT NULL DEFAULT '',
              pixelfed  TEXT NOT NULL DEFAULT '',
              instagram TEXT NOT NULL DEFAULT '',
              youtube   TEXT NOT NULL DEFAULT '',
              github    TEXT NOT NULL DEFAULT '',
              bluesky   TEXT NOT NULL DEFAULT ''
            )"
        );

        $existing = $pdo->query('SELECT * FROM social ORDER BY id ASC LIMIT 1')->fetch();
        if ($existing) {
            foreach ($form as $key => $_) {
                $form[$key] = (string) ($existing[$key] ?? '');
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();

            foreach ($form as $key => $_) {
                $form[$key] = trim((string) ($_POST[$key] ?? ''));
            }

            // Validar que los valores no vacíos sean URLs válidas.
            foreach ($form as $key => $val) {
                if ($val !== '' && filter_var($val, FILTER_VALIDATE_URL) === false) {
                    $error = 'El valor de "' . $key . '" no es una URL válida.';
                    break;
                }
            }

            if ($error === '') {
                $cols   = implode(', ', SOCIAL_FIELDS);
                $params = [];
                foreach (SOCIAL_FIELDS as $f) {
                    $params[':' . $f] = $form[$f];
                }

                if ($existing) {
                    $sets  = implode(', ', array_map(fn ($f) => "$f = :$f", SOCIAL_FIELDS));
                    $params[':id'] = (int) $existing['id'];
                    $pdo->prepare("UPDATE social SET $sets WHERE id = :id")->execute($params);
                } else {
                    $placeholders = implode(', ', array_map(fn ($f) => ":$f", SOCIAL_FIELDS));
                    $pdo->prepare("INSERT INTO social ($cols) VALUES ($placeholders)")->execute($params);
                }

                clearWebCache();
                $notice = 'Redes sociales guardadas correctamente.';
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error en social_management.php: ' . $e->getMessage() . "\n";
        exit;
    }

    return compact('form', 'error', 'notice');
}
