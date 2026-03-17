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
 * Valida el Bearer token de Authorization contra api_tokens.
 * Actualiza last_used_at si el token es válido.
 * Devuelve true si el token es válido y no ha expirado.
 */
function apiAuth(PDO $pdo): bool
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

    $stmt = $pdo->prepare(
        "SELECT id FROM api_tokens
         WHERE token = :token
           AND (expires_at IS NULL OR expires_at > datetime('now'))
         LIMIT 1"
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    // Registrar el momento de último uso del token.
    $upd = $pdo->prepare("UPDATE api_tokens SET last_used_at = datetime('now') WHERE id = :id");
    $upd->execute([':id' => (int) $row['id']]);

    return true;
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
