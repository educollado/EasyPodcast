<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/access_control.php';

test('establishAdminSession conserva múltiples podcasts asignados', function () {
    $_SESSION = [];
    establishAdminSession(['id' => 7, 'username' => 'user@example.com', 'is_global' => 0, 'podcast_ids' => [2, 5]]);
    assert_true(!adminSessionIsGlobal());
    assert_eq(7, adminSessionUserId());
    assert_eq([2, 5], adminSessionPodcastIds());
});

test('establishAdminSession reconoce al administrador global', function () {
    $_SESSION = [];
    establishAdminSession(['id' => 1, 'username' => 'admin', 'is_global' => 1, 'podcast_ids' => []]);
    assert_true(adminSessionIsGlobal());
});
