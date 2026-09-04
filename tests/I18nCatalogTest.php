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

test('las cadenas de seguridad por IP están traducidas en todos los idiomas', function () {
    $messages = [
        'Seguridad',
        'Restringe el acceso administrativo por dirección IP',
        'Configura restricciones adicionales para proteger el acceso administrativo.',
        'Estas direcciones o rangos no son válidos: %s',
        'Se ha desactivado el bloqueo de acceso a admin.php por IP.',
        'El bloqueo de acceso a admin.php por IP se ha actualizado correctamente.',
        'Bloqueo de IP a admin.php',
        'Solo las direcciones indicadas podrán abrir la página de acceso. Se admiten direcciones y rangos CIDR en IPv4 e IPv6.',
        'Direcciones IP o rangos permitidos',
        'Añade una dirección o rango por línea. Para desactivar el bloqueo, deja la lista vacía y guarda.',
        'Importante: incluye tu IP actual antes de guardar para no perder el acceso a admin.php.',
        'Guardar seguridad',
        'Añade al menos una dirección IP o rango antes de habilitar el bloqueo.',
        'La confirmación ha caducado o la lista ha cambiado. Vuelve a iniciar la activación.',
        'El bloqueo de acceso a admin.php por IP se ha habilitado correctamente.',
        'La acción de seguridad solicitada no es válida.',
        'Habilitado',
        'Deshabilitado',
        'Añade una dirección o rango por línea.',
        'Has solicitado habilitar el bloqueo por IP. Esta medida puede impedirte volver a acceder a admin.php. La habilitas voluntariamente y bajo tu propia responsabilidad. Comprueba que has incluido tu IP actual y pulsa «Estoy seguro» para confirmar.',
        'Estoy seguro',
        'Guardar cambios',
        'Deshabilitar bloqueo',
        'Habilitar bloqueo',
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
                substr_count($message, '%s'),
                substr_count($translations[$message], '%s'),
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
        'Al activarlo, cada podcast tendrá su propio directorio para páginas, episodios y feeds. Las URLs de imágenes y audios no cambiarán.',
        'Contenido de la portada principal',
        'Resumen de todos los podcasts',
        '¿Qué quieres mostrar en la portada principal?',
        'Muestra los podcasts publicados ordenados por última actualización.',
        'Un único podcast',
        'Muestra la portada del podcast seleccionado.',
        'Podcast que se mostrará',
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
        'El podcast principal no existe.',
        'Marca la confirmación y escribe exactamente el título del podcast principal para desactivar Multipodcast.',
        'No se pueden trasladar medios porque el directorio contiene enlaces simbólicos.',
        'No se pueden devolver los medios porque ya existe un archivo con el mismo nombre en la ruta original.',
        'Se ha creado una copia de seguridad de cada podcast secundario eliminado:',
        'Descargar %s',
        'Al desactivar Multipodcast, «%s» volverá a ser el único podcast. Se crearán copias de seguridad y se borrarán definitivamente los otros %d podcasts junto con sus datos y medios.',
        'Convertir el podcast actual a Multipodcast',
        '«%s» será el podcast principal. Sus imágenes, audios y URLs multimedia se conservarán sin cambios.',
        'Directorio del podcast principal',
        'Debe estar libre y solo puede contener letras minúsculas, números y guiones.',
        'Confirmar la desactivación de Multipodcast',
        'Las imágenes, audios y URLs multimedia de «%s» no cambiarán. Los podcasts secundarios se borrarán después de crear sus copias ZIP.',
        'Entiendo que los podcasts secundarios se borrarán definitivamente.',
        'Escribe el título del podcast principal para confirmar',
        'Podcast creado dentro de',
        'Usuarios',
        'Asigna a cada usuario uno o varios podcasts',
        'Crea cuentas limitadas a la gestión de uno o varios podcasts.',
        'Crear usuario',
        'Apellidos',
        'Email',
        'Podcasts que puede gestionar',
        'Usuario activo',
        'Usuarios existentes',
        'Todavía no hay usuarios de podcast.',
        'Nueva contraseña (opcional)',
        'Eliminar usuario',
        '¿Eliminar este usuario y todos sus tokens API?',
        'Usuario guardado correctamente.',
        'Usuario eliminado correctamente.',
        'Nombre, apellidos y email válido son obligatorios.',
        'Selecciona al menos un podcast.',
        'Selecciona únicamente podcasts válidos.',
        'Ya existe un usuario con ese email.',
        'El usuario no existe.',
        'No se puede borrar un podcast que tiene usuarios asignados. Reasígnalos primero.',
        'Cada token hereda los permisos de su usuario. Los usuarios de podcast solo pueden usarlo en los podcasts que tienen asignados; el administrador global puede usar un token administrativo en cualquier podcast indicando su directorio en la URL.',
        'Usuario o email',
        'Tus tokens heredan tu acceso y solo pueden actuar sobre el podcast seleccionado.',
        'La gestión de usuarios exige un token admin del administrador global. Los tokens de usuarios de podcast no pueden usar estos endpoints.',
        'Listar podcasts asignables',
        'Listar usuarios',
        'Obtener usuario',
        'Las respuestas incluyen los podcasts asignados, pero nunca incluyen contraseñas.',
        'Crear usuario y asignar podcasts',
        'Mínimo 8 caracteres',
        'Lista de IDs de podcasts',
        'Lista de directorios de podcasts; puede usarse en lugar de podcast_ids',
        'Actualizar usuario y sus asignaciones',
        'Solo se modifican los campos enviados. Una contraseña vacía conserva la contraseña actual; las listas de podcasts sustituyen todas las asignaciones anteriores.',
        'Borrar usuario y sus tokens API',
        'No se pudo crear el usuario.',
        'No se pudo actualizar el usuario.',
        'No se pudo eliminar el usuario.',
        'Debes asignar al menos un podcast.',
        'podcast_ids debe ser una lista.',
        'La lista contiene podcasts no válidos.',
        'podcast_slugs debe ser una lista.',
        'is_active debe ser true o false.',
        'Administrador global',
        'Configura la cuenta que administra toda la instalación',
        'Cuenta del administrador global',
        'Define el usuario de acceso y los datos de la cuenta con control completo sobre el Multipodcast.',
        'Usuario de acceso',
        'Contraseña actual para confirmar',
        'Guardar administrador',
        'La cuenta administradora global no existe.',
        'El usuario de acceso y la contraseña actual son obligatorios.',
        'El usuario de acceso es demasiado largo.',
        'El email del administrador no es válido.',
        'Ya existe una cuenta con ese usuario o email.',
        'Datos del administrador actualizados correctamente.',
        'No se pudo guardar la cuenta administradora.',
        'Idioma de Multipodcast',
        'Se aplica a la portada-resumen y al panel global. Cada podcast conserva su propio idioma.',
        'El idioma de Multipodcast no es válido.',
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
