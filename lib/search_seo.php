<?php

declare(strict_types=1);

require_once __DIR__ . '/seo_helpers.php';

/**
 * Construye todas las variables SEO para la página de búsqueda. Función pura (sin acceso a BD).
 * La búsqueda siempre es noindex; incluye prev/next para paginación de resultados.
 *
 * @return array{podcastTitle:string, podcastAuthor:string, podcastDescription:string, podcastImage:string, baseSeoUrl:string, canonicalUrl:string, robotsContent:string, prevUrl:?string, nextUrl:?string, metaDescription:string, ogImage:string, rssUrl:string}
 */
function buildSearchSeoData(?array $podcast, string $query, int $page, int $totalPages): array
{
    $p = $podcast ?? [];

    $podcastTitle       = trim((string) ($p['title'] ?? 'Podcast'));
    $podcastAuthor      = trim((string) ($p['owner_name'] ?? ''));
    // Fallback de autor: owner_name -> author.
    if ($podcastAuthor === '') {
        $podcastAuthor  = trim((string) ($p['author'] ?? ''));
    }
    $podcastDescription = trim((string) ($p['description'] ?? ''));
    $podcastImage       = trim((string) ($p['image_url'] ?? ''));
    $baseSeoUrl         = resolveSeoBaseUrl((string) ($p['link'] ?? ''));

    $queryParams   = ['q' => $query];
    if ($page > 1) {
        $queryParams['page'] = $page;
    }
    $searchPath = podcastSeoPath($p, 'search');
    $canonicalPath = $searchPath . ($query !== '' ? ('?' . http_build_query($queryParams)) : '');
    $canonicalUrl  = toAbsoluteSeoUrl($canonicalPath, $baseSeoUrl);
    $robotsContent = 'noindex,follow';

    $metaDescription = $query === ''
        ? 'Busca episodios por título o contenido.'
        : ('Resultados para "' . $query . '" en ' . $podcastTitle . '.');

    $ogImage = $podcastImage !== '' ? toAbsoluteSeoUrl($podcastImage, $baseSeoUrl) : toAbsoluteSeoUrl('/favicon.ico', $baseSeoUrl);
    $rssUrl  = toAbsoluteSeoUrl(podcastSeoPath($p, 'feed.xml'), $baseSeoUrl);

    // URLs de paginación para <link rel="prev/next">.
    $prevUrl = null;
    if ($query !== '' && $page > 1) {
        $prevParams = ['q' => $query];
        if ($page > 2) {
            $prevParams['page'] = $page - 1;
        }
        $prevUrl = toAbsoluteSeoUrl($searchPath . '?' . http_build_query($prevParams), $baseSeoUrl);
    }
    $nextUrl = null;
    if ($query !== '' && $page < $totalPages) {
        $nextParams = ['q' => $query, 'page' => $page + 1];
        $nextUrl = toAbsoluteSeoUrl($searchPath . '?' . http_build_query($nextParams), $baseSeoUrl);
    }

    return compact(
        'podcastTitle', 'podcastAuthor', 'podcastDescription', 'podcastImage',
        'baseSeoUrl', 'canonicalUrl', 'robotsContent', 'prevUrl', 'nextUrl',
        'metaDescription', 'ogImage', 'rssUrl'
    );
}
