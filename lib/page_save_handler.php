<?php

declare(strict_types=1);

require_once __DIR__ . '/cache_service.php';
require_once __DIR__ . '/i18n.php';

/** Slugs reservados que no pueden usarse como ruta de primer nivel. */
const PAGE_RESERVED_SLUGS = ['admin', 'feed', 'search', 'cache', 'audios', 'images', 'assets', 'backups', 'robots'];

/**
 * Valida que una página en edición no se asigne a sí misma como padre.
 * Lanza RuntimeException si la selección es inválida.
 */
function ensurePageParentIsValid(?int $parentId, ?int $editId): void
{
    if ($editId !== null && $parentId !== null && $parentId === $editId) {
        throw new RuntimeException(__('Una página no puede ser su propia página padre.'));
    }
}

/**
 * Devuelve la ruta de preview para el formulario de páginas.
 * Prioriza full_path persistido (útil en páginas hijas) y, si no existe, usa slug.
 */
function buildPagePreviewPath(array $form): string
{
    $fullPath = trim((string) ($form['full_path'] ?? ''));
    if ($fullPath !== '') {
        return '/' . ltrim($fullPath, '/');
    }

    $slug = trim((string) ($form['slug'] ?? ''));
    if ($slug !== '') {
        return '/' . ltrim($slug, '/');
    }

    return '/';
}

/**
 * Valida el formulario de página.
 * Devuelve ['errors' => string[], 'form' => array].
 */
function validatePageForm(array $post): array
{
    $parentIdRaw = (string) ($post['parent_id'] ?? '');
    $form = [
        'title'      => trim((string) ($post['title'] ?? '')),
        'slug'       => trim(strtolower((string) ($post['slug'] ?? ''))),
        'content'    => (string) ($post['content'] ?? ''),
        'parent_id'  => $parentIdRaw !== '' ? (int) $parentIdRaw : null,
        'full_path'  => trim((string) ($post['current_full_path'] ?? '')),
        'sort_order' => (int) ($post['sort_order'] ?? 0),
        'status'     => in_array($post['status'] ?? '', ['draft', 'published'], true)
                            ? (string) $post['status']
                            : 'draft',
    ];

    $errors = [];
    if ($form['title'] === '') {
        $errors[] = __('El título es obligatorio.');
    }
    if ($form['slug'] === '') {
        $errors[] = __('El slug es obligatorio.');
    } elseif (!preg_match('/^[a-z0-9-]+$/', $form['slug'])) {
        $errors[] = __('El slug solo puede contener letras minúsculas, números y guiones.');
    }

    return ['errors' => $errors, 'form' => $form];
}

/**
 * Guarda (inserta o actualiza) una página.
 * Lanza RuntimeException si hay error de negocio (slug reservado, ruta duplicada, padre no encontrado).
 */
function savePage(PDO $pdo, array $form, ?int $editId): void
{
    $slug     = $form['slug'];
    $parentId = $form['parent_id'];

    ensurePageParentIsValid($parentId, $editId);

    // Calcular full_path según si es hija o de primer nivel.
    if ($parentId !== null) {
        $parentStmt = $pdo->prepare("SELECT slug FROM pages WHERE id = ? LIMIT 1");
        $parentStmt->execute([$parentId]);
        $parentRow = $parentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$parentRow) {
            throw new RuntimeException(__('La página padre seleccionada no existe.'));
        }
        $fullPath = $parentRow['slug'] . '/' . $slug;
    } else {
        $fullPath = $slug;
        if (in_array($slug, PAGE_RESERVED_SLUGS, true)) {
            throw new RuntimeException(__('El slug "%s" es una ruta reservada del sistema.', $slug));
        }
    }

    // Verificar unicidad de full_path (excluir la propia página en modo edición).
    if ($editId !== null) {
        $uniqueStmt = $pdo->prepare("SELECT id FROM pages WHERE full_path = ? AND id != ? LIMIT 1");
        $uniqueStmt->execute([$fullPath, $editId]);
    } else {
        $uniqueStmt = $pdo->prepare("SELECT id FROM pages WHERE full_path = ? LIMIT 1");
        $uniqueStmt->execute([$fullPath]);
    }
    if ($uniqueStmt->fetch()) {
        throw new RuntimeException(__('Ya existe una página con la ruta "%s".', $fullPath));
    }

    $now = date('Y-m-d H:i:s');
    if ($editId !== null) {
        $stmt = $pdo->prepare(
            "UPDATE pages
             SET title=?, slug=?, full_path=?, content=?, parent_id=?, sort_order=?, status=?, updated_at=?
             WHERE id=?"
        );
        $stmt->execute([
            $form['title'],
            $slug,
            $fullPath,
            $form['content'],
            $parentId,
            $form['sort_order'],
            $form['status'],
            $now,
            $editId,
        ]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO pages (title, slug, full_path, content, parent_id, sort_order, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $form['title'],
            $slug,
            $fullPath,
            $form['content'],
            $parentId,
            $form['sort_order'],
            $form['status'],
            $now,
            $now,
        ]);
    }

    clearWebCache();
}

/**
 * Borra una página.
 * Devuelve string de error si no es posible, '' si se borró correctamente.
 */
function deletePage(PDO $pdo, int $id): string
{
    $childStmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE parent_id = ?");
    $childStmt->execute([$id]);
    if ((int) $childStmt->fetchColumn() > 0) {
        return __('No se puede borrar una página que tiene subpáginas. Borra primero las subpáginas.');
    }

    $stmt = $pdo->prepare("DELETE FROM pages WHERE id = ?");
    $stmt->execute([$id]);
    clearWebCache();
    return '';
}

/**
 * Carga los datos para la lista de páginas del panel de administración.
 * Devuelve ['pagesList', 'error', 'notice'].
 */
function loadPagesManagementData(string $dbPath): array
{
    $result = [
        'pagesList' => [],
        'error'     => '',
        'notice'    => '',
    ];

    if (isset($_GET['notice']) && $_GET['notice'] === 'deleted') {
        $result['notice'] = __('Página borrada correctamente.');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $tableExists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='pages' LIMIT 1")
            ->fetchColumn();
        if (!$tableExists) {
            return $result;
        }

        // Padres primero, luego hijos indentados: SELECT ordenado por parent_id (NULL primero) y sort_order.
        $parents = $pdo
            ->query("SELECT id, title, full_path, status, sort_order, parent_id FROM pages WHERE parent_id IS NULL ORDER BY sort_order ASC, id ASC")
            ->fetchAll();

        if (!$parents) {
            return $result;
        }

        $parentIds    = array_column($parents, 'id');
        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, title, full_path, status, sort_order, parent_id FROM pages
             WHERE parent_id IN ($placeholders)
             ORDER BY parent_id ASC, sort_order ASC, id ASC"
        );
        $stmt->execute($parentIds);
        $children = $stmt->fetchAll();

        $childrenByParent = [];
        foreach ($children as $child) {
            $childrenByParent[(int) $child['parent_id']][] = $child;
        }

        // Construye lista aplanada con marca de nivel para indentación visual.
        $list = [];
        foreach ($parents as $parent) {
            $parent['level'] = 0;
            $list[] = $parent;
            foreach ($childrenByParent[(int) $parent['id']] ?? [] as $child) {
                $child['level'] = 1;
                $list[] = $child;
            }
        }
        $result['pagesList'] = $list;
    } catch (Throwable $e) {
        $result['error'] = __('Error al cargar las páginas: %s', $e->getMessage());
    }

    return $result;
}

/**
 * Carga los datos para el formulario admin de crear/editar página.
 * Maneja GET (mostrar form) y POST (procesar y guardar).
 * Devuelve ['form', 'isEditing', 'editingPageId', 'topLevelPages', 'error', 'notice'].
 */
function loadAddPageData(string $dbPath): array
{
    $result = [
        'form'          => defaultPageForm(),
        'isEditing'     => false,
        'editingPageId' => null,
        'topLevelPages' => [],
        'error'         => '',
        'notice'        => '',
    ];

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Páginas top-level disponibles para el selector de padre.
        $tableExists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='pages' LIMIT 1")
            ->fetchColumn();
        if ($tableExists) {
            $result['topLevelPages'] = $pdo
                ->query("SELECT id, title FROM pages WHERE parent_id IS NULL ORDER BY sort_order ASC, id ASC")
                ->fetchAll();
        }

        $editId = isset($_GET['page_id']) && $_GET['page_id'] !== '' ? (int) $_GET['page_id'] : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_once __DIR__ . '/csrf.php';
            csrf_verify();

            // Borrado desde el formulario de gestión.
            if (isset($_POST['delete_page_id'])) {
                $deleteId    = (int) $_POST['delete_page_id'];
                $deleteError = deletePage($pdo, $deleteId);
                if ($deleteError !== '') {
                    $result['error'] = $deleteError;
                } else {
                    header('Location: pages_management.php?notice=deleted');
                    exit;
                }
                return $result;
            }

            $editId    = isset($_POST['page_id']) && $_POST['page_id'] !== '' ? (int) $_POST['page_id'] : null;
            $validated = validatePageForm($_POST);

            if ($validated['errors']) {
                $result['error']         = implode(' ', $validated['errors']);
                $result['form']          = $validated['form'];
                $result['isEditing']     = $editId !== null;
                $result['editingPageId'] = $editId;
                return $result;
            }

            try {
                savePage($pdo, $validated['form'], $editId);
                $result['notice']        = $editId !== null ? __('Página actualizada correctamente.') : __('Página creada correctamente.');
                $result['isEditing']     = $editId !== null;
                $result['editingPageId'] = $editId;

                // Recarga el form con los datos guardados para que el usuario los vea actualizados.
                if ($editId !== null) {
                    $rowStmt = $pdo->prepare("SELECT * FROM pages WHERE id = ? LIMIT 1");
                    $rowStmt->execute([$editId]);
                    $saved = $rowStmt->fetch();
                    if ($saved) {
                        $result['form'] = formFromPageRow($saved);
                    }
                } else {
                    $result['form'] = defaultPageForm();
                }
            } catch (RuntimeException $e) {
                $result['error']         = $e->getMessage();
                $result['form']          = $validated['form'];
                $result['isEditing']     = $editId !== null;
                $result['editingPageId'] = $editId;
            }

            return $result;
        }

        // GET: cargar datos de la página si es edición.
        if ($editId !== null) {
            $stmt = $pdo->prepare("SELECT * FROM pages WHERE id = ? LIMIT 1");
            $stmt->execute([$editId]);
            $row = $stmt->fetch();
            if ($row) {
                $result['form']          = formFromPageRow($row);
                $result['isEditing']     = true;
                $result['editingPageId'] = $editId;
            } else {
                $result['error'] = __('Página no encontrada.');
            }
        }
    } catch (Throwable $e) {
        $result['error'] = __('Error interno: %s', $e->getMessage());
    }

    return $result;
}

/** Formulario vacío por defecto. */
function defaultPageForm(): array
{
    return [
        'title'      => '',
        'slug'       => '',
        'content'    => '',
        'parent_id'  => null,
        'full_path'  => '',
        'sort_order' => 0,
        'status'     => 'draft',
    ];
}

/** Convierte una fila de BD al array de formulario. */
function formFromPageRow(array $row): array
{
    return [
        'title'      => (string) ($row['title'] ?? ''),
        'slug'       => (string) ($row['slug'] ?? ''),
        'content'    => (string) ($row['content'] ?? ''),
        'parent_id'  => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
        'full_path'  => (string) ($row['full_path'] ?? ''),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'status'     => (string) ($row['status'] ?? 'draft'),
    ];
}
