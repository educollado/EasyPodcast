<?php

declare(strict_types=1);

require_once __DIR__ . '/seo_helpers.php';

/**
 * Construye todas las variables SEO/JSON-LD para la página de detalle de episodio.
 * Función pura (sin acceso a BD). Genera JSON-LD PodcastEpisode solo cuando el episodio existe.
 *
 * @return array{podcastTitle:string, podcastAuthor:string, podcastDescription:string, cover:string, baseSeoUrl:string, canonicalUrl:string, robotsContent:string, episodeTitle:string, pageTitle:string, metaDescription:string, ogImage:string, rssUrl:string, episodeJsonLd:string}
 */
function buildEpisodeSeoData(?array $podcast, ?array $episode, string $year, string $month, string $slug, string $error): array
{
    $p = $podcast ?? [];
    $e = $episode ?? [];

    $podcastTitle       = trim((string) ($p['title'] ?? 'Podcast'));
    $podcastAuthor      = trim((string) ($p['owner_name'] ?? ''));
    // Fallback de autor: owner_name -> author.
    if ($podcastAuthor === '') {
        $podcastAuthor  = trim((string) ($p['author'] ?? ''));
    }
    $podcastDescription = trim((string) ($p['description'] ?? ''));
    $podcastImage       = trim((string) ($p['image_url'] ?? ''));
    $cover              = trim((string) ($e['image_url'] ?? ''));
    // Fallback de imagen del episodio a imagen del podcast.
    if ($cover === '') {
        $cover = trim((string) ($p['image_url'] ?? ''));
    }
    $baseSeoUrl    = resolveSeoBaseUrl((string) ($p['link'] ?? ''));
    $canonicalPath = '/' . $year . '/' . $month . '/' . $slug;
    $canonicalUrl  = toAbsoluteSeoUrl($canonicalPath, $baseSeoUrl);
    $robotsContent = $error !== '' ? 'noindex,follow' : 'index,follow';
    $episodeTitle  = (string) ($e['title'] ?? $podcastTitle);
    $pageTitle     = $episode !== null ? ($episodeTitle . ' | ' . $podcastTitle) : $podcastTitle;
    $metaDescription = compactMetaText((string) ($e['content'] ?? ''), 160);
    if ($metaDescription === '') {
        $metaDescription = compactMetaText((string) ($p['description'] ?? ''), 160);
    }
    if ($metaDescription === '') {
        $metaDescription = 'Escucha este episodio en ' . $podcastTitle . '.';
    }
    $ogImage = $cover !== '' ? toAbsoluteSeoUrl($cover, $baseSeoUrl) : toAbsoluteSeoUrl('/favicon.ico', $baseSeoUrl);
    $rssUrl  = toAbsoluteSeoUrl('/feed.xml', $baseSeoUrl);

    $episodeJsonLd = '{}';
    if ($episode !== null) {
        $episodeData = [
            '@context'      => 'https://schema.org',
            '@type'         => 'PodcastEpisode',
            'name'          => $episodeTitle,
            'url'           => $canonicalUrl,
            'description'   => (string) ($e['content'] ?? ''),
            'datePublished' => (string) ($e['pub_date'] ?? ''),
            'dateModified'  => (string) ($e['updated_at'] ?? $e['pub_date'] ?? ''),
            'partOfSeries'  => [
                '@type' => 'PodcastSeries',
                'name'  => $podcastTitle,
                'url'   => toAbsoluteSeoUrl('/', $baseSeoUrl),
            ],
        ];
        if (!empty($e['audio_url'])) {
            $episodeData['associatedMedia'] = [
                '@type'      => 'MediaObject',
                'contentUrl' => toAbsoluteSeoUrl((string) $e['audio_url'], $baseSeoUrl),
            ];
        }
        if ($cover !== '') {
            $episodeData['image'] = $ogImage;
        }
        $encoded = json_encode($episodeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && $encoded !== '') {
            $episodeJsonLd = $encoded;
        }
    }

    return compact(
        'podcastTitle', 'podcastAuthor', 'podcastDescription',
        'podcastImage', 'cover', 'baseSeoUrl', 'canonicalUrl', 'robotsContent',
        'episodeTitle', 'pageTitle', 'metaDescription',
        'ogImage', 'rssUrl', 'episodeJsonLd'
    );
}
