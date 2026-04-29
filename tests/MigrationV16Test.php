<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/migration_runner.php';

function migrationV16TestHasSqliteDriver(): bool
{
    return in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function migrationV16TestCreateLegacyDatabase(): array
{
    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-mig16-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la BD temporal de migración');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        "CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY,
            token TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            expires_at TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            name TEXT NOT NULL DEFAULT '',
            last_used_at TEXT
        )"
    );

    return ['pdo' => $pdo, 'dbPath' => $dbPath];
}

test('migration_v16: reconstruye api_tokens legacy con token UNIQUE sin romper la migración', function () {
    if (!migrationV16TestHasSqliteDriver()) {
        return;
    }

    ['pdo' => $pdo, 'dbPath' => $dbPath] = migrationV16TestCreateLegacyDatabase();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (token, user_id, name, created_at)
             VALUES (:token, :user_id, :name, datetime("now"))'
        );
        $stmt->execute([
            ':token' => 'token-antiguo-uno',
            ':user_id' => 1,
            ':name' => 'uno',
        ]);
        $stmt->execute([
            ':token' => 'token-antiguo-dos',
            ':user_id' => 2,
            ':name' => 'dos',
        ]);

        migration_v16($pdo);

        $rows = $pdo->query('SELECT id, token, token_hash, token_suffix, scope, name FROM api_tokens ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        assert_eq(2, count($rows));
        assert_eq('', (string) $rows[0]['token']);
        assert_eq('', (string) $rows[1]['token']);
        assert_eq(hash('sha256', 'token-antiguo-uno'), (string) $rows[0]['token_hash']);
        assert_eq(hash('sha256', 'token-antiguo-dos'), (string) $rows[1]['token_hash']);
        assert_eq(substr('token-antiguo-uno', -8), (string) $rows[0]['token_suffix']);
        assert_eq(substr('token-antiguo-dos', -8), (string) $rows[1]['token_suffix']);
        assert_eq('content', (string) $rows[0]['scope']);
        assert_eq('content', (string) $rows[1]['scope']);

        $insert = $pdo->prepare(
            'INSERT INTO api_tokens (token, token_hash, token_suffix, scope, name, user_id)
             VALUES (:token, :token_hash, :token_suffix, :scope, :name, :user_id)'
        );
        $insert->execute([
            ':token' => '',
            ':token_hash' => hash('sha256', 'token-nuevo'),
            ':token_suffix' => substr('token-nuevo', -8),
            ':scope' => 'admin',
            ':name' => 'nuevo',
            ':user_id' => 3,
        ]);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn();
        assert_eq(3, $count);
    } finally {
        $pdo = null;
        @unlink($dbPath);
    }
});
