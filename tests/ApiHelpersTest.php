<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/api_helpers.php';

function apiHelpersTestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function apiHelpersTestCreateDatabase(): array
{
    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-api-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de test');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        "CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY,
            podcast_id INTEGER NOT NULL DEFAULT 1,
            token TEXT NOT NULL DEFAULT '',
            token_hash TEXT NOT NULL DEFAULT '',
            token_suffix TEXT NOT NULL DEFAULT '',
            scope TEXT NOT NULL DEFAULT 'content',
            name TEXT NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL,
            expires_at TEXT,
            last_used_at TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )"
    );
    $pdo->exec('CREATE TABLE management (id INTEGER PRIMARY KEY, is_global INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE management_podcasts (management_id INTEGER, podcast_id INTEGER)');
    $pdo->exec('INSERT INTO management (id, is_global) VALUES (1, 1)');

    return ['pdo' => $pdo, 'dbPath' => $dbPath];
}

function apiHelpersTestResetAuthHeaders(): void
{
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}

test('hashApiTokenValue: genera un sha256 estable', function () {
    assert_eq(hash('sha256', 'mi-token'), hashApiTokenValue('mi-token'));
});

test('apiTokenHasScope: admin cubre content y content no cubre admin', function () {
    assert_true(apiTokenHasScope(['id' => 1, 'scope' => 'admin'], 'content'));
    assert_true(apiTokenHasScope(['id' => 1, 'scope' => 'admin'], 'admin'));
    assert_true(apiTokenHasScope(['id' => 2, 'scope' => 'content'], 'content'));
    assert_true(!apiTokenHasScope(['id' => 2, 'scope' => 'content'], 'admin'));
    assert_true(!apiTokenHasScope(['id' => 3, 'scope' => 'admin', 'owner_is_global' => false], 'admin'));
});

test('apiAuth: autentica tokens hash y actualiza last_used_at', function () {
    if (!apiHelpersTestHasSqliteDriver()) {
        return;
    }

    ['pdo' => $pdo, 'dbPath' => $dbPath] = apiHelpersTestCreateDatabase();
    apiHelpersTestResetAuthHeaders();

    try {
        $token = 'token-seguro';
        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (token_hash, token_suffix, scope, name, user_id, created_at)
             VALUES (:token_hash, :token_suffix, :scope, :name, :user_id, datetime("now"))'
        );
        $stmt->execute([
            ':token_hash' => hashApiTokenValue($token),
            ':token_suffix' => substr($token, -8),
            ':scope' => 'content',
            ':name' => 'integracion',
            ':user_id' => 1,
        ]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $auth = apiAuth($pdo);

        assert_eq(1, (int) ($auth['id'] ?? 0));
        assert_eq(1, (int) ($auth['podcast_id'] ?? 0));
        assert_eq('content', (string) ($auth['scope'] ?? ''));
        assert_true((bool) ($auth['owner_is_global'] ?? false));

        $row = $pdo->query('SELECT last_used_at FROM api_tokens WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        assert_true(!empty($row['last_used_at']), 'Se esperaba last_used_at actualizado.');
    } finally {
        apiHelpersTestResetAuthHeaders();
        $pdo = null;
        @unlink($dbPath);
    }
});

test('apiAuth: migra tokens legacy en claro a hash y conserva el alcance', function () {
    if (!apiHelpersTestHasSqliteDriver()) {
        return;
    }

    ['pdo' => $pdo, 'dbPath' => $dbPath] = apiHelpersTestCreateDatabase();
    apiHelpersTestResetAuthHeaders();

    try {
        $token = 'legacy-token-admin';
        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (token, scope, name, user_id, created_at)
             VALUES (:token, :scope, :name, :user_id, datetime("now"))'
        );
        $stmt->execute([
            ':token' => $token,
            ':scope' => 'admin',
            ':name' => 'legacy',
            ':user_id' => 1,
        ]);

        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $auth = apiAuth($pdo);

        assert_eq(1, (int) ($auth['id'] ?? 0));
        assert_eq('admin', (string) ($auth['scope'] ?? ''));
        assert_true((bool) ($auth['owner_is_global'] ?? false));

        $row = $pdo->query('SELECT token, token_hash, token_suffix, scope, last_used_at FROM api_tokens WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        assert_eq('', (string) ($row['token'] ?? ''));
        assert_eq(hashApiTokenValue($token), (string) ($row['token_hash'] ?? ''));
        assert_eq(substr($token, -8), (string) ($row['token_suffix'] ?? ''));
        assert_eq('admin', (string) ($row['scope'] ?? ''));
        assert_true(!empty($row['last_used_at']), 'Se esperaba last_used_at actualizado tras migrar token legacy.');
    } finally {
        apiHelpersTestResetAuthHeaders();
        $pdo = null;
        @unlink($dbPath);
    }
});
