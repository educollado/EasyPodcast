<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/admin_account_handler.php';

test('la cuenta global exige contraseña actual y actualiza el usuario de sesión', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }

    $dbPath = tempnam(sys_get_temp_dir(), 'easypodcast-admin-account-');
    if ($dbPath === false) {
        throw new RuntimeException('No se pudo crear la base temporal');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE management (
                id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT,
                first_name TEXT, last_name TEXT, email TEXT UNIQUE,
                is_global INTEGER, is_active INTEGER, updated_at TEXT
            )'
        );
        $insert = $pdo->prepare(
            "INSERT INTO management (id, username, password, first_name, last_name, email, is_global, is_active)
             VALUES (1, :username, :password, '', '', NULL, 1, 1)"
        );
        $insert->execute([':username' => 'admin', ':password' => password_hash('actual-segura', PASSWORD_DEFAULT)]);
        $pdo = null;

        $_SESSION = ['admin_user_id' => 1, 'admin_user' => 'admin', 'admin_is_global' => 1, 'csrf_token' => 'token-test'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => 'token-test',
            'username' => 'administrador',
            'first_name' => 'Ana',
            'last_name' => 'García',
            'email' => 'admin@example.com',
            'current_password' => 'actual-segura',
        ];

        $data = loadAdminAccountData($dbPath);
        assert_eq('', $data['error']);
        assert_eq('administrador', $_SESSION['admin_user']);

        $verify = new PDO('sqlite:' . $dbPath);
        $row = $verify->query('SELECT username, first_name, last_name, email FROM management WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        assert_eq('administrador', $row['username']);
        assert_eq('admin@example.com', $row['email']);
    } finally {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $_SESSION = [];
        @unlink($dbPath);
    }
});

test('la pantalla de cuenta global protege el acceso y escapa sus campos', function () {
    $source = file_get_contents(__DIR__ . '/../admin_account.php');
    assert_true(is_string($source));
    assert_contains('requireGlobalAdminAccess();', $source);
    assert_contains('csrf_token()', $source);
    assert_contains("esc(\$form['username'])", $source);
    assert_contains('name="current_password"', $source);
});
