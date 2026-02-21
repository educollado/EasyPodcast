<?php

declare(strict_types=1);

require_once __DIR__ . '/seo_helpers.php';

// Construye todas las variables SEO/JSON-LD para la portada. Función pura (sin BD).
function buildHomeSeoData(?array $podcast, int $page, int $totalPages, string $error): array
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
    $canonicalPath      = $page > 1 ? '/?page=' . $page : '/';
    $canonicalUrl       = toAbsoluteSeoUrl($canonicalPath, $baseSeoUrl);
    $robotsContent      = $error !== '' ? 'noindex,follow' : ($page > 1 ? 'noindex,follow' : 'index,follow');
    $prevUrl            = null;
    if ($page > 1) {
        $prevPath = $page === 2 ? '/' : '/?page=' . ($page - 1);
        $prevUrl  = toAbsoluteSeoUrl($prevPath, $baseSeoUrl);
    }
    $nextUrl = null;
    if ($page < $totalPages) {
        $nextUrl = toAbsoluteSeoUrl('/?page=' . ($page + 1), $baseSeoUrl);
    }
    $metaDescription = compactMetaText((string) ($p['description'] ?? ''), 160);
    if ($metaDescription === '') {
        $metaDescription = 'Podcast en EasyPodcast: episodios, reproductor y feed RSS.';
    }
    $ogImage    = $podcastImage !== '' ? toAbsoluteSeoUrl($podcastImage, $baseSeoUrl) : toAbsoluteSeoUrl('/favicon.ico', $baseSeoUrl);
    $rssUrl     = toAbsoluteSeoUrl('/feed.xml', $baseSeoUrl);
    $seriesData = [
        '@context'    => 'https://schema.org',
        '@type'       => 'PodcastSeries',
        'name'        => $podcastTitle,
        'url'         => toAbsoluteSeoUrl('/', $baseSeoUrl),
        'description' => (string) ($p['description'] ?? ''),
        'inLanguage'  => (string) ($p['language'] ?? 'es-ES'),
    ];
    if ($podcastAuthor !== '') {
        $seriesData['author'] = ['@type' => 'Person', 'name' => $podcastAuthor];
    }
    if ($podcastImage !== '') {
        $seriesData['image'] = $ogImage;
    }
    $seriesJsonLd = json_encode($seriesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($seriesJsonLd) || $seriesJsonLd === '') {
        $seriesJsonLd = '{}';
    }

    return compact(
        'podcastTitle', 'podcastAuthor', 'podcastDescription', 'podcastImage',
        'baseSeoUrl', 'canonicalUrl', 'robotsContent', 'prevUrl', 'nextUrl',
        'metaDescription', 'ogImage', 'rssUrl', 'seriesJsonLd'
    );
}
