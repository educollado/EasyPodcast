<?php

declare(strict_types=1);

require_once __DIR__ . '/canonical_redirect.php';
require_once __DIR__ . '/lib/view_helpers.php';
require_once __DIR__ . '/lib/social_handler.php';

session_start();
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: admin.php');
    exit;
}

$dbPath = getenv('PODCAST_DB_PATH') ?: __DIR__ . '/podcast.sqlite';
enforceCanonicalHostFromPodcastLink($dbPath);
header('X-Robots-Tag: noindex, nofollow, noarchive');

$data = loadSocialData($dbPath);
extract($data); // form, error, notice

$labels = [
    'blog'      => [__('Blog / Web personal'), 'https://tudominio.com'],
    'linkedin'  => ['LinkedIn',            'https://linkedin.com/in/usuario'],
    'mastodon'  => ['Mastodon',            'https://mastodon.social/@usuario'],
    'x'         => ['X (Twitter)',         'https://x.com/usuario'],
    'pixelfed'  => ['Pixelfed',            'https://pixelfed.social/usuario'],
    'instagram' => ['Instagram',           'https://instagram.com/usuario'],
    'youtube'   => ['YouTube',             'https://youtube.com/@usuario'],
    'github'    => ['GitHub',              'https://github.com/usuario'],
    'bluesky'   => ['Bluesky',            'https://bsky.app/profile/usuario'],
];
?>
<!doctype html>
<html lang="<?= esc(i18n_html_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= __('Redes Sociales') ?></title>
  <link rel="stylesheet" href="/assets/css/admin-common.css">
</head>
<body>
  <?php $currentAdminPage = 'social'; require __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-wrap">
    <main class="card">
      <h1><?= __('Redes Sociales') ?></h1>
      <p><?= __('Los enlaces configurados aparecerán como iconos en la cabecera de la web pública.') ?></p>

      <?php if ($error !== ''): ?>
        <div class="error"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="notice"><?= esc($notice) ?></div>
      <?php endif; ?>

      <form method="post" action="social_management.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">

        <div class="grid two" style="margin-top:.5rem;">
          <?php foreach ($labels as $key => [$label, $placeholder]): ?>
            <label>
              <?= esc($label) ?>
              <input type="url" name="<?= esc($key) ?>"
                     value="<?= esc($form[$key]) ?>"
                     placeholder="<?= esc($placeholder) ?>">
            </label>
          <?php endforeach; ?>
        </div>

        <div class="actions" style="margin-top:1rem;">
          <button class="btn" type="submit"><?= __('Guardar redes sociales') ?></button>
        </div>
      </form>
    </main>
  </div>
</body>
</html>
