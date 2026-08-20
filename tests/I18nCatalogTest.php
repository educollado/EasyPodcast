<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/i18n.php';

test('las cadenas nuevas del panel están traducidas en todos los idiomas', function () {
    $messages = [
        'Imágenes del podcast',
        'Imagen del podcast',
        'Vista previa de la imagen del podcast',
        'Imagen del hero',
        'Vista previa de la imagen del hero',
        'Imagen del hero (URL)',
        'O subir imagen para el hero',
        'Déjala vacía para mantener la cabecera actual sin hero.',
        'La imagen subida se recorta y optimiza automáticamente para la cabecera.',
        'La imagen se recortará para cubrir la cabecera sin cambiar su tamaño.',
        'Mostrando %d-%d de %d',
        'Seleccionar archivo',
        'No se ha seleccionado ningún archivo',
        '%d archivos seleccionados',
        'Alcance',
        'Usa alcance content para automatizaciones normales. Reserva admin solo para operaciones sensibles como la actualización de la aplicación.',
        'Los tokens con alcance',
        'cubren la API de contenidos y mantenimiento habitual. El endpoint',
        'requiere un token con alcance',
        'Este endpoint exige un token con alcance',
        'Si está vacío se genera automáticamente',
    ];
    $localeFiles = glob(__DIR__ . '/../locale/*.po') ?: [];

    assert_eq(8, count($localeFiles));

    foreach ($localeFiles as $localeFile) {
        $translations = i18n_parse_po($localeFile);
        foreach ($messages as $message) {
            assert_true(
                isset($translations[$message]) && $translations[$message] !== '',
                basename($localeFile) . ' no traduce: ' . $message
            );
            assert_eq(
                substr_count($message, '%d'),
                substr_count($translations[$message], '%d'),
                basename($localeFile) . ' no conserva los marcadores de: ' . $message
            );
        }
    }
});
