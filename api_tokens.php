<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/api_tokens_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadApiTokensData($dbPath);
extract($data); // tokens, error, notice, newToken
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>" data-theme="<?= esc(adminTheme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc(__('Tokens API')) ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
  <link rel="stylesheet" href="/assets/css/themes.css">
  <style>
    .tokens-table-wrap {
      margin-top: 1rem;
    }
    .tokens-table {
      width: 100%;
    }
    .tokens-table code {
      font-size: .88rem;
      white-space: nowrap;
    }
    .token-empty {
      color: var(--muted);
      font-style: italic;
    }
    @media (max-width: 760px) {
      .tokens-table {
        min-width: 0;
        border-collapse: separate;
        border-spacing: 0;
      }
      .tokens-table thead {
        display: none;
      }
      .tokens-table,
      .tokens-table tbody,
      .tokens-table tr,
      .tokens-table td {
        display: block;
        width: 100%;
      }
      .tokens-table tr {
        margin-bottom: .9rem;
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        background: var(--card);
      }
      .tokens-table td {
        min-width: 0;
        display: grid;
        grid-template-columns: minmax(112px, 38%) 1fr;
        gap: .65rem;
        padding: .7rem .85rem;
        border-bottom: 1px solid var(--border);
      }
      .tokens-table td:last-child {
        border-bottom: 0;
      }
      .tokens-table td::before {
        content: attr(data-label);
        color: var(--muted);
        font-size: .78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
      }
      .tokens-table td form {
        margin: 0;
      }
      .tokens-table td .btn {
        width: 100%;
        justify-content: center;
      }
    }
    @media (max-width: 420px) {
      .tokens-table td {
        grid-template-columns: 1fr;
        gap: .35rem;
      }
    }
  </style>
</head>
<body>
  <?php $currentAdminPage = 'api_tokens'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Tokens de API') ?></h1>
      <p><?= __('Genera tokens para autenticar peticiones a la API REST. Cada token solo se muestra una vez al crearlo.') ?></p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <?php if ($newToken !== ''): ?>
        <div class="notice" style="word-break:break-all;">
          <strong><?= __('Token generado (guárdalo ahora):') ?></strong><br>
          <code style="font-size:0.9em;user-select:all"><?= esc($newToken) ?></code>
        </div>
      <?php endif; ?>

      <!-- Formulario para generar nuevo token -->
      <section>
        <h2><?= __('Generar nuevo token') ?></h2>
        <form method="post" action="api_tokens.php" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
          <input type="hidden" name="action" value="generate">
          <div class="grid two">
            <label>
              <?= __('Nombre del token *') ?>
              <input type="text" name="token_name" placeholder="<?= esc(__('p.ej. integración-n8n')) ?>" required>
            </label>
            <label>
              <?= __('Fecha de expiración (opcional)') ?>
              <input type="datetime-local" name="expires_at">
            </label>
          </div>
          <button type="submit" class="btn"><?= __('Generar token') ?></button>
        </form>
      </section>

      <!-- Lista de tokens existentes -->
      <section style="margin-top:2rem">
        <h2><?= __('Tokens activos') ?></h2>
        <?php if (empty($tokens)): ?>
          <p><?= __('No hay tokens creados.') ?></p>
        <?php else: ?>
          <div class="table-wrap tokens-table-wrap">
            <table class="table tokens-table">
              <thead>
                <tr>
                  <th><?= __('Nombre') ?></th>
                  <th><?= __('Token (últimos 8 chars)') ?></th>
                  <th><?= __('Creado') ?></th>
                  <th><?= __('Expira') ?></th>
                  <th><?= __('Último uso') ?></th>
                  <th><?= __('Acción') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tokens as $t): ?>
                  <tr>
                    <td data-label="<?= esc(__('Nombre')) ?>"><?= esc($t['name'] ?? '') ?></td>
                    <td data-label="<?= esc(__('Token (últimos 8 chars)')) ?>"><code>...<?= esc(substr((string) $t['token'], -8)) ?></code></td>
                    <td data-label="<?= esc(__('Creado')) ?>"><?= esc((string) ($t['created_at'] ?? '')) ?></td>
                    <td data-label="<?= esc(__('Expira')) ?>">
                      <?= $t['expires_at'] ? esc((string) $t['expires_at']) : '<span class="token-empty">' . esc(__('Sin expiración')) . '</span>' ?>
                    </td>
                    <td data-label="<?= esc(__('Último uso')) ?>">
                      <?= $t['last_used_at'] ? esc((string) $t['last_used_at']) : '<span class="token-empty">' . esc(__('Nunca')) . '</span>' ?>
                    </td>
                    <td data-label="<?= esc(__('Acción')) ?>">
                      <form method="post" action="api_tokens.php" style="display:inline"
                            onsubmit="return confirm('<?= esc(__('¿Revocar este token?')) ?>')">
                        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
                        <input type="hidden" name="action"   value="revoke">
                        <input type="hidden" name="token_id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><?= __('Revocar') ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <p style="margin-top:2rem">
        <a href="api_docs.php" class="btn"><?= __('→ Ver documentación de la API') ?></a>
      </p>
    </main>
  </div>
</body>
</html>
