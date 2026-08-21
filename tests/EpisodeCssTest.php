<?php

declare(strict_types=1);

test('las imágenes del contenido de episodios no desbordan su columna', function () {
    $css = file_get_contents(__DIR__ . '/../assets/css/episode.css');
    assert_true(is_string($css));
    assert_matches('/\.detail\s*>\s*div\s*\{[^}]*min-width:\s*0;/s', $css);
    assert_matches('/\.detail\s+\.desc\s+img\s*\{[^}]*max-width:\s*100%;[^}]*height:\s*auto;/s', $css);
});
