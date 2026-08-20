<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/access_control.php';

/**
 * @return array<string, string>
 */
function apiTokenScopeOptions(): array
{
    $options = ['content' => __('Contenido')];
    if (adminSessionIsGlobal()) {
        $options['admin'] = __('Administración total');
    }
    return $options;
}

/**
 * Devuelve una etiqueta legible para el alcance del token.
 */
function apiTokenScopeLabel(string $scope): string
{
    $options = apiTokenScopeOptions();
    return $options[normalizeApiTokenScope($scope)] ?? $options['content'];
}

/**
 * Carga la lista de tokens y procesa acciones POST (generar/revocar).
 * Usado por la página de administración api_tokens.php.
 *
 * @return array{tokens: array, error: string, notice: string, newToken: string}
 */
function loadApiTokensData(string $dbPath): array
{
    $tokens   = [];
    $error    = '';
    $notice   = '';
    $newToken = '';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $tableExists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='api_tokens' LIMIT 1")
            ->fetchColumn();

        if ($tableExists) {
            $tokensStmt = $pdo->prepare("SELECT id, name, token_suffix, scope, expires_at, created_at, last_used_at FROM api_tokens WHERE podcast_id = :podcast_id AND user_id = :user_id ORDER BY created_at DESC");
            $tokensStmt->execute([':podcast_id' => activePodcastId($pdo), ':user_id' => adminSessionUserId()]);
            $tokens = $tokensStmt->fetchAll();
        }

        // Recoger token generado desde el flash de sesión (PRG).
        if (isset($_SESSION['api_token_flash'])) {
            $newToken = (string) $_SESSION['api_token_flash'];
            unset($_SESSION['api_token_flash']);
            $notice = __('Token generado correctamente. Cópialo ahora: no se mostrará de nuevo.');
        }

        // Aviso de revocación desde query string.
        if (($_GET['notice'] ?? '') === 'revoked') {
            $notice = __('Token revocado correctamente.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'generate') {
                $name      = trim((string) ($_POST['token_name'] ?? ''));
                $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
                $scope     = adminSessionIsGlobal() ? normalizeApiTokenScope((string) ($_POST['token_scope'] ?? 'content')) : 'content';

                if ($name === '') {
                    $error = __('El nombre del token es obligatorio.');
                } else {
                    $newToken = generateApiToken(
                        $pdo,
                        $name,
                        $expiresAt !== '' ? $expiresAt : null,
                        $scope
                    );
                    // PRG: guardar token en sesión y redirigir para evitar reenvío del formulario.
                    $_SESSION['api_token_flash'] = $newToken;
                    header('Location: api_tokens.php');
                    exit;
                }
            } elseif ($action === 'revoke') {
                $revokeId = (int) ($_POST['token_id'] ?? 0);
                if ($revokeId > 0) {
                    revokeApiToken($pdo, $revokeId);
                    header('Location: api_tokens.php?notice=revoked');
                    exit;
                }
            }
        }
    } catch (Throwable $e) {
        $error = __('Error al gestionar tokens: %s', $e->getMessage());
    }

    return compact('tokens', 'error', 'notice', 'newToken');
}

/**
 * Genera un token aleatorio de 64 caracteres hex, lo almacena en BD y devuelve su valor en claro.
 * El token nunca se puede recuperar después; solo se muestra una vez al generarlo.
 */
function generateApiToken(PDO $pdo, string $name, ?string $expiresAt, string $scope): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hashApiTokenValue($token);
    $tokenSuffix = substr($token, -8);
    $scope = normalizeApiTokenScope($scope);

    $stmt = $pdo->prepare(
        "INSERT INTO api_tokens (podcast_id, token, token_hash, token_suffix, scope, name, user_id, expires_at, created_at)
         VALUES (:podcast_id, :token, :token_hash, :token_suffix, :scope, :name, :user_id, :expires_at, datetime('now'))"
    );
    $stmt->execute([
        ':podcast_id' => activePodcastId($pdo),
        ':token'      => '',
        ':token_hash' => $tokenHash,
        ':token_suffix' => $tokenSuffix,
        ':scope'      => $scope,
        ':name'       => $name,
        ':user_id' => adminSessionUserId(),
        ':expires_at' => $expiresAt,
    ]);

    return $token;
}

/**
 * Revoca (elimina) un token de la BD por su id.
 */
function revokeApiToken(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE id = :id AND podcast_id = :podcast_id AND user_id = :user_id');
    $stmt->execute([':id' => $id, ':podcast_id' => activePodcastId($pdo), ':user_id' => adminSessionUserId()]);
}
