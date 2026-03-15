<?php

declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/i18n.php';

/** Campos de la tabla social en el orden en que se muestran. */
const SOCIAL_FIELDS = ['blog', 'linkedin', 'mastodon', 'x', 'pixelfed', 'instagram', 'youtube', 'github', 'bluesky'];

/**
 * Devuelve el mapa de iconos SVG por clave de red social.
 *
 * @return array<string, array{label:string, svg:string}>
 */
function getSocialIcons(): array
{
    return [
        'blog' => [
            'label' => 'Blog',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>',
        ],
        'mastodon' => [
            'label' => 'Mastodon',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M21.33 8.57c0-4.34-2.84-5.61-2.84-5.61-1.43-.66-3.9-.94-6.45-.96h-.06c-2.56.02-5.02.3-6.45.96 0 0-2.84 1.27-2.84 5.61 0 .99-.02 2.18.01 3.44.1 4.24.78 8.43 4.7 9.46 1.81.48 3.36.58 4.61.51 2.27-.13 3.54-.81 3.54-.81l-.07-1.65s-1.62.51-3.44.45c-1.8-.06-3.71-.19-4-2.41a4.52 4.52 0 0 1-.04-.62s1.77.43 4.01.54c1.37.06 2.66-.08 3.97-.24 2.5-.3 4.68-1.84 4.96-3.25.43-2.22.4-5.42.4-5.42zm-3.35 5.59h-2.08V9.06c0-1.07-.45-1.62-1.36-1.62-1 0-1.5.65-1.5 1.93v2.79h-2.07V9.36c0-1.28-.5-1.93-1.5-1.93-.9 0-1.36.55-1.36 1.62v5.1H6.03V8.9c0-1.07.27-1.93.82-2.56.57-.63 1.31-.95 2.24-.95 1.07 0 1.88.41 2.42 1.23l.52.87.52-.87c.53-.82 1.34-1.23 2.41-1.23.93 0 1.67.32 2.24.95.55.63.82 1.49.82 2.56v5.26z"/></svg>',
        ],
        'x' => [
            'label' => 'X',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.402 6.231H2.746l7.73-8.835L1.254 2.25H8.08l4.259 5.626L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>',
        ],
        'pixelfed' => [
            'label' => 'Pixelfed',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46a2.78 2.78 0 0 0-1.95 1.96A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.95-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"/><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="#fff"/></svg>',
        ],
        'github' => [
            'label' => 'GitHub',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0 1 12 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0 0 22 12.017C22 6.484 17.522 2 12 2z"/></svg>',
        ],
        'bluesky' => [
            'label' => 'Bluesky',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.04.415-.057-.138.022-.276.04-.415.057-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 0 1-.415-.057c.14.017.279.036.415.057 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.479 0-.69-.139-1.861-.902-2.206-.659-.299-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8z"/></svg>',
        ],
    ];
}

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
                    $error = __('El valor de "%s" no es una URL válida.', $key);
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
                $notice = __('Redes sociales guardadas correctamente.');
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
