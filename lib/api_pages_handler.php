<?php

declare(strict_types=1);

require_once __DIR__ . '/api_helpers.php';
require_once __DIR__ . '/page_save_handler.php';

/**
 * GET /api/v1/pages
 * Lista paginada de páginas. Parámetros: page, limit, status.
 */
function apiListPages(PDO $pdo, array $params): void
{
    $page   = max(1, (int) ($params['page']   ?? 1));
    $limit  = min(100, max(1, (int) ($params['limit'] ?? 20)));
    $status = (string) ($params['status'] ?? '');
    $offset = ($page - 1) * $limit;

    $where = '';
    $binds = [];

    if ($status !== '' && in_array($status, ['draft', 'published'], true)) {
        $where          = 'WHERE status = :status';
        $binds[':status'] = $status;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pages $where");
    $countStmt->execute($binds ?: []);
    $total = (int) $countStmt->fetchColumn();

    $fetchBinds            = $binds;
    $fetchBinds[':limit']  = $limit;
    $fetchBinds[':offset'] = $offset;

    $stmt = $pdo->prepare(
        "SELECT * FROM pages $where
         ORDER BY parent_id ASC NULLS FIRST, sort_order ASC, id ASC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->execute($fetchBinds);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    apiJsonResponse([
        'success' => true,
        'data'    => [
            'items'       => $pages,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ],
    ]);
}

/**
 * GET /api/v1/pages/{id}
 */
function apiGetPage(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page) {
        apiError('Página no encontrada.', 404);
    }

    apiJsonResponse(['success' => true, 'data' => $page]);
}

/**
 * POST /api/v1/pages
 * Campos requeridos: title, slug. Opcionales: content, parent_id, sort_order, status.
 */
function apiCreatePage(PDO $pdo, array $body): void
{
    $validated = validatePageForm($body);

    if (!empty($validated['errors'])) {
        apiError(implode(' ', $validated['errors']));
    }

    try {
        savePage($pdo, $validated['form'], null);
    } catch (RuntimeException $e) {
        apiError($e->getMessage());
    }

    $lastId = (int) $pdo->lastInsertId();
    $stmt   = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $lastId]);
    $created = $stmt->fetch(PDO::FETCH_ASSOC);

    apiJsonResponse(['success' => true, 'data' => $created ?: []], 201);
}

/**
 * POST /api/v1/pages/{id}
 * Solo los campos enviados sobreescriben los existentes.
 * Para indicar la ruta actual al renombrar, envía current_full_path.
 */
function apiUpdatePage(PDO $pdo, int $id, array $body): void
{
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        apiError('Página no encontrada.', 404);
    }

    // Si no se proporciona current_full_path, usamos el full_path existente en BD.
    if (!array_key_exists('current_full_path', $body)) {
        $body['current_full_path'] = $existing['full_path'];
    }

    // Mezclar datos existentes con los nuevos para completar campos omitidos.
    $merged = array_merge(
        [
            'title'      => $existing['title'],
            'slug'       => $existing['slug'],
            'content'    => $existing['content'],
            'parent_id'  => $existing['parent_id'],
            'sort_order' => $existing['sort_order'],
            'status'     => $existing['status'],
        ],
        $body
    );

    $validated = validatePageForm($merged);

    if (!empty($validated['errors'])) {
        apiError(implode(' ', $validated['errors']));
    }

    try {
        savePage($pdo, $validated['form'], $id);
    } catch (RuntimeException $e) {
        apiError($e->getMessage());
    }

    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);

    apiJsonResponse(['success' => true, 'data' => $updated ?: []]);
}

/**
 * DELETE /api/v1/pages/{id}
 */
function apiDeletePage(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT id FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    if (!$stmt->fetch()) {
        apiError('Página no encontrada.', 404);
    }

    $deleteError = deletePage($pdo, $id);

    if ($deleteError !== '') {
        apiError($deleteError);
    }

    apiJsonResponse(['success' => true, 'data' => ['deleted' => true]]);
}
