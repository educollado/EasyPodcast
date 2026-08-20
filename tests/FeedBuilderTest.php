<?php

declare(strict_types=1);

require_once __DIR__ . '/../feed_builder.php';

test('buildFeedTrackingUrl: genera URL absoluta al endpoint de tracking del feed', function () {
    $url = buildFeedTrackingUrl('https://example.com', 7);

    assert_eq('https://example.com/track?episode_id=7&action=feed', $url);
});

test('buildFeedTrackingUrl: mantiene el tracking en la raíz si la base contiene un slug', function () {
    $url = buildFeedTrackingUrl('https://example.com/mi-podcast', 7);

    assert_eq('https://example.com/track?episode_id=7&action=feed', $url);
});

test('normalizeEnclosureMime: usa el MIME original aunque la URL de tracking no tenga extensión', function () {
    $mime = normalizeEnclosureMime('audio/mpeg', 'https://example.com/track.php?episode_id=7&action=feed');

    assert_eq('audio/mpeg', $mime);
});
