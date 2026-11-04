<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/stats_downloads_handler.php';

test('el catálogo chino cubre el catálogo inglés y conserva los marcadores', function () {
    $english = i18n_parse_po(__DIR__ . '/../locale/en_US.po');
    $chinese = i18n_parse_po(__DIR__ . '/../locale/zh_CN.po');
    $pattern = '/%(?:\d+\$)?[-+0#]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]/';

    foreach ($english as $message => $translation) {
        assert_true(isset($chinese[$message]) && trim($chinese[$message]) !== '', 'Falta traducción china: ' . $message);
        preg_match_all($pattern, str_replace('%%', '', $message), $expected);
        preg_match_all($pattern, str_replace('%%', '', $chinese[$message]), $actual);
        sort($expected[0]);
        sort($actual[0]);
        assert_eq($expected[0], $actual[0], 'Marcadores diferentes: ' . $message);
    }
});

test('el idioma chino se carga con UTF-8 y formatea valores dinámicos', function () {
    $previous = i18n_current_locale();
    try {
        i18n_load('zh_CN');
        assert_eq('zh_CN', i18n_current_locale());
        assert_eq('zh-CN', i18n_html_lang());
        assert_eq('保存', __('Guardar'));
        $formatted = __('Mostrando %d-%d de %d', 1, 10, 20);
        assert_contains('1', $formatted);
        assert_contains('10', $formatted);
        assert_contains('20', $formatted);
        assert_true(preg_match('/\p{Han}/u', $formatted) === 1);
        assert_eq('2026年11月', formatMonthYear(2026, 11));
    } finally {
        i18n_load($previous);
    }
});

test('los selectores de idioma muestran el nombre chino', function () {
    foreach (['admin.php', 'podcast_management.php', 'multipodcast.php', 'multipodcast_management.php'] as $page) {
        $source = file_get_contents(__DIR__ . '/../' . $page);
        assert_true(is_string($source));
        assert_contains("'zh_CN' => '简体中文'", $source, $page . ' no incluye el nombre del idioma chino');
    }
});
