<?php

declare(strict_types=1);

require_once __DIR__ . '/podcast_context.php';

/**
 * Devuelve páginas publicadas de primer nivel con sus hijos publicados.
 * Se usa en header.php para la barra de navegación pública.
 * Falla silenciosamente devolviendo [] si hay cualquier error.
 */
function getPublishedPagesForNav(string $dbPath): array
{
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Verificar que la tabla existe (instalaciones sin migración v6 aún).
        $tableExists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='pages' LIMIT 1")
            ->fetchColumn();
        if (!$tableExists) {
            return [];
        }

        // Páginas de primer nivel publicadas, ordenadas por sort_order.
        $podcastId = activePodcastId($pdo);
        $parentsStmt = $pdo->prepare("SELECT id, title, full_path FROM pages WHERE podcast_id = :podcast_id AND status='published' AND parent_id IS NULL ORDER BY sort_order ASC, id ASC");
        $parentsStmt->execute([':podcast_id' => $podcastId]);
        $parents = $parentsStmt->fetchAll();
        if (!$parents) {
            return [];
        }

        // Hijos publicados de todos los padres, en una sola consulta.
        $parentIds    = array_column($parents, 'id');
        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, title, full_path, parent_id FROM pages
             WHERE podcast_id = ? AND status='published' AND parent_id IN ($placeholders)
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute(array_merge([$podcastId], $parentIds));
        $children = $stmt->fetchAll();

        // Agrupa hijos por parent_id.
        $childrenByParent = [];
        foreach ($children as $child) {
            $childrenByParent[(int) $child['parent_id']][] = $child;
        }

        // Combina padres con sus hijos.
        $result = [];
        foreach ($parents as $parent) {
            $parent['children'] = $childrenByParent[(int) $parent['id']] ?? [];
            $result[] = $parent;
        }

        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Carga los datos de una página por su full_path.
 * Si $adminPreview=true también devuelve borradores.
 * Devuelve ['page', 'parent', 'children', 'httpStatus', 'error', 'podcast'].
 */
function loadPageData(string $dbPath, string $fullPath, bool $adminPreview = false): array
{
    $result = [
        'page'       => null,
        'parent'     => null,
        'children'   => [],
        'httpStatus' => 200,
        'error'      => '',
        'podcast'    => null,
    ];

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $result['podcast'] = activePodcast($pdo);
        $podcastId = (int) ($result['podcast']['id'] ?? 0);

        // Verificar que la tabla existe.
        $tableExists = (bool) $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='pages' LIMIT 1")
            ->fetchColumn();
        if (!$tableExists) {
            $result['httpStatus'] = 404;
            $result['error']      = 'Página no encontrada.';
            return $result;
        }

        // Busca la página.
        $sql = $adminPreview
            ? "SELECT * FROM pages WHERE podcast_id = ? AND full_path = ? LIMIT 1"
            : "SELECT * FROM pages WHERE podcast_id = ? AND full_path = ? AND status = 'published' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$podcastId, $fullPath]);
        $page = $stmt->fetch();

        if (!$page) {
            $result['httpStatus'] = 404;
            $result['error']      = 'Página no encontrada.';
            return $result;
        }
        $result['page'] = $page;

        // Hijos publicados (o todos si adminPreview).
        $childSql = $adminPreview
            ? "SELECT id, title, full_path FROM pages WHERE podcast_id = ? AND parent_id = ? ORDER BY sort_order ASC, id ASC"
            : "SELECT id, title, full_path FROM pages WHERE podcast_id = ? AND parent_id = ? AND status = 'published' ORDER BY sort_order ASC, id ASC";
        $childStmt = $pdo->prepare($childSql);
        $childStmt->execute([$podcastId, (int) $page['id']]);
        $result['children'] = $childStmt->fetchAll();

        // Info del padre para breadcrumb (solo en páginas hijas).
        if ($page['parent_id'] !== null) {
            $parentStmt = $pdo->prepare("SELECT id, title, full_path FROM pages WHERE podcast_id = ? AND id = ? LIMIT 1");
            $parentStmt->execute([$podcastId, (int) $page['parent_id']]);
            $result['parent'] = $parentStmt->fetch() ?: null;
        }
    } catch (Throwable $e) {
        $result['httpStatus'] = 500;
        $result['error']      = 'Error interno al cargar la página.';
    }

    return $result;
}

/**
 * Construye los metadatos SEO de una página estática.
 * Devuelve: podcastTitle, podcastAuthor, podcastDescription, cover,
 *           baseSeoUrl, canonicalUrl, robotsContent, pageTitle,
 *           metaDescription, ogImage, rssUrl.
 */
function buildPageSeoData(array $podcast, ?array $page, string $error): array
{
    require_once __DIR__ . '/seo_helpers.php';

    $podcastTitle       = trim((string) ($podcast['title'] ?? ''));
    $podcastAuthor      = trim((string) ($podcast['author'] ?? ''));
    $podcastDescription = trim((string) ($podcast['description'] ?? ''));
    $cover              = trim((string) ($podcast['image_url'] ?? ''));
    $baseSeoUrl         = resolveSeoBaseUrl($podcast['link'] ?? null);
    $rssUrl             = toAbsoluteSeoUrl(podcastSeoPath($podcast, 'feed.xml'), $baseSeoUrl);

    if ($error !== '' || $page === null) {
        return [
            'podcastTitle'       => $podcastTitle,
            'podcastAuthor'      => $podcastAuthor,
            'podcastDescription' => $podcastDescription,
            'podcastImage'       => $cover,
            'baseSeoUrl'         => $baseSeoUrl,
            'canonicalUrl'       => $baseSeoUrl,
            'robotsContent'      => 'noindex, nofollow',
            'pageTitle'          => 'Página no encontrada — ' . $podcastTitle,
            'metaDescription'    => '',
            'ogImage'            => toAbsoluteSeoUrl($cover, $baseSeoUrl),
            'rssUrl'             => $rssUrl,
        ];
    }

    $pageTitle = trim((string) ($page['title'] ?? ''));
    $fullTitle = $pageTitle !== '' ? $pageTitle . ' — ' . $podcastTitle : $podcastTitle;
    $canonical = toAbsoluteSeoUrl(podcastSeoPath($podcast, (string) ($page['full_path'] ?? '')), $baseSeoUrl);

    // Meta description a partir del contenido HTML (sin etiquetas, max 160 chars).
    $plainContent = strip_tags((string) ($page['content'] ?? ''));
    $metaDesc     = mb_substr(trim($plainContent), 0, 160);

    return [
        'podcastTitle'       => $podcastTitle,
        'podcastAuthor'      => $podcastAuthor,
        'podcastDescription' => $podcastDescription,
        'podcastImage'       => $cover,
        'baseSeoUrl'         => $baseSeoUrl,
        'canonicalUrl'       => $canonical,
        'robotsContent'      => 'index, follow',
        'pageTitle'          => $fullTitle,
        'metaDescription'    => $metaDesc,
        'ogImage'            => toAbsoluteSeoUrl($cover, $baseSeoUrl),
        'rssUrl'             => $rssUrl,
    ];
}
