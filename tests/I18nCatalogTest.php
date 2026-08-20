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

test('las cadenas de Multipodcast están traducidas en todos los idiomas', function () {
    $messages = [
        'Multipodcast',
        'Panel de administración del Podcast %s',
        'Panel de administración del Multipodcast',
        'Esta imagen se mostrará únicamente cuando la portada principal sea el resumen de todos los podcasts.',
        'La URL de la imagen del hero no es válida.',
        'No se pudo subir la imagen del hero: %s',
        'Título del resumen',
        'Subtítulo del resumen',
        'Tema del resumen',
        'El tema del resumen no es válido.',
        'Al desactivar Multipodcast, solo se mostrará el podcast principal «%s». Los demás podcasts y sus datos se conservarán, pero no serán accesibles públicamente hasta volver a activar Multipodcast.',
        'Podcast principal',
        'Marcar como principal',
        'Podcast principal actualizado correctamente.',
        'Elegir podcast',
        'Ver podcasts ↗',
        'Ver podcast ↗',
        'Podcasts',
        'Crea, selecciona y configura los podcasts de la instalación',
        'El directorio debe contener únicamente letras minúsculas, números y guiones.',
        'Ese directorio está reservado por la aplicación.',
        'El título del podcast es obligatorio.',
        'Ese directorio ya está siendo utilizado por otro podcast.',
        'Podcast creado correctamente.',
        'Configuración multipodcast guardada correctamente.',
        'Directorio del podcast actualizado correctamente.',
        'Podcast borrado correctamente. Descarga y conserva su copia de seguridad.',
        'No se pudo crear el directorio de medios del podcast.',
        'Todos los podcasts deben tener un directorio antes de activar Multipodcast.',
        'El podcast elegido para la portada no existe.',
        'El podcast no existe.',
        'No se puede cambiar el directorio porque la ruta de medios de destino ya existe.',
        'No se pudo mover el directorio de medios del podcast.',
        'Escribe exactamente el título del podcast para confirmar el borrado.',
        'No se puede borrar el único podcast de la instalación.',
        'No se puede borrar sin crear antes una copia consistente porque ZipArchive o SQLite3 no están disponibles.',
        'No se pudo crear el directorio de backups.',
        'No se pudo crear la copia de seguridad del podcast.',
        'No se pudo crear una copia consistente de la base de datos.',
        'No se pudo finalizar la copia de seguridad del podcast.',
        'Descargar copia de seguridad',
        'Configuración Multipodcast',
        'Activar Multipodcast',
        'Al activarlo, cada podcast usará su propio directorio y cambiarán sus URLs públicas. La portada principal mostrará el resumen o el podcast elegido; las URLs antiguas de episodios solo se redirigirán si eliges un podcast para la portada.',
        'Contenido de la portada principal',
        'Resumen de todos los podcasts',
        'Podcasts disponibles',
        'Podcasts disponibles (ordenados por última actualización)',
        'Mostrar este podcast en la página principal de resumen.',
        'Directorio:',
        '%d capítulos',
        'Administrar podcast',
        'Directorio del podcast',
        'Cambiar directorio',
        'Se creará una copia ZIP y se borrarán definitivamente el podcast, sus capítulos, estadísticas y medios. ¿Continuar?',
        'Escribe el título para confirmar',
        'Crear backup y borrar podcast',
        'Crear un podcast nuevo',
        'Crear podcast',
        'Descubre todos los podcasts disponibles y sus feeds RSS.',
        'Todos nuestros podcasts, en un solo lugar.',
        'Todavía no hay podcasts disponibles.',
        'No se pudo generar el sitemap.',
        'No hay un podcast definido para el feed principal.',
        'Ese directorio está ocupado en el servidor. Elige otro.',
    ];

    foreach (glob(__DIR__ . '/../locale/*.po') ?: [] as $localeFile) {
        $translations = i18n_parse_po($localeFile);
        foreach ($messages as $message) {
            assert_true(isset($translations[$message]) && $translations[$message] !== '', basename($localeFile) . ' no traduce: ' . $message);
            assert_eq(substr_count($message, '%d'), substr_count($translations[$message], '%d'));
            assert_eq(substr_count($message, '%s'), substr_count($translations[$message], '%s'));
        }
    }
});
