<?php

declare(strict_types=1);

/**
 * Envía una respuesta JSON con el código HTTP indicado y termina la ejecución.
 */
function apiJsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Termina la ejecución con un error JSON.
 */
function apiError(string $msg, int $status = 400): never
{
    apiJsonResponse(['success' => false, 'error' => $msg], $status);
}

/**
 * Hash estable del token API para no persistirlo en claro.
 */
function hashApiTokenValue(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Normaliza el alcance de un token API.
 */
function normalizeApiTokenScope(?string $scope): string
{
    return $scope === 'admin' ? 'admin' : 'content';
}

/**
 * Comprueba si el token autenticado cubre el alcance solicitado.
 *
 * @param array{id:int,scope:string} $auth
 */
function apiTokenHasScope(array $auth, string $requiredScope): bool
{
    $scope = normalizeApiTokenScope($auth['scope'] ?? '');
    if ($requiredScope === 'admin') {
        return $scope === 'admin';
    }

    return in_array($scope, ['content', 'admin'], true);
}

/**
 * Exige un alcance concreto para continuar.
 *
 * @param array{id:int,scope:string} $auth
 */
function apiRequireScope(array $auth, string $requiredScope): void
{
    if (!apiTokenHasScope($auth, $requiredScope)) {
        apiError('El token autenticado no tiene permisos suficientes para esta operación.', 403);
    }
}

/**
 * Valida el Bearer token de Authorization contra api_tokens.
 * Actualiza last_used_at si el token es válido.
 * Devuelve sus metadatos si el token es válido y no ha expirado.
 */
function apiAuth(PDO $pdo): array|false
{
    $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($authHeader === '') {
        // Apache puede renombrar la cabecera al pasar por mod_rewrite.
        $authHeader = (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }

    if (!str_starts_with($authHeader, 'Bearer ')) {
        return false;
    }

    $token = trim(substr($authHeader, 7));
    if ($token === '') {
        return false;
    }

    $tokenHash = hashApiTokenValue($token);
    $stmt = $pdo->prepare(
        "SELECT id, scope FROM api_tokens
         WHERE token_hash = :token_hash
           AND (expires_at IS NULL OR expires_at > datetime('now'))
         LIMIT 1"
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $legacyStmt = $pdo->prepare(
            "SELECT id, token, scope FROM api_tokens
             WHERE token = :token
               AND (expires_at IS NULL OR expires_at > datetime('now'))
             LIMIT 1"
        );
        $legacyStmt->execute([':token' => $token]);
        $row = $legacyStmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $migrate = $pdo->prepare(
                'UPDATE api_tokens
                    SET token = :empty_token,
                        token_hash = :token_hash,
                        token_suffix = :token_suffix,
                        scope = :scope
                  WHERE id = :id'
            );
            $migrate->execute([
                ':empty_token' => '',
                ':token_hash' => $tokenHash,
                ':token_suffix' => substr($token, -8),
                ':scope' => normalizeApiTokenScope((string) ($row['scope'] ?? '')),
                ':id' => (int) $row['id'],
            ]);
        }
    }

    if (!$row) {
        return false;
    }

    // Registrar el momento de último uso del token.
    $upd = $pdo->prepare("UPDATE api_tokens SET last_used_at = datetime('now') WHERE id = :id");
    $upd->execute([':id' => (int) $row['id']]);

    return [
        'id' => (int) $row['id'],
        'scope' => normalizeApiTokenScope((string) ($row['scope'] ?? '')),
    ];
}

/**
 * Parsea el cuerpo de la petición.
 * Devuelve el array de datos de $_POST si es multipart/form-data,
 * o decodifica JSON si el Content-Type es application/json.
 */
function apiParseBody(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

    if (str_contains($contentType, 'application/json')) {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}
