# Changelog

## Siguiente release

- **Multipodcast opcional**: una única instalación puede alojar varios podcasts aislados por directorio, con selector administrativo, portada agregada o podcast destacado, feeds y sitemaps propios, y alias compatibles en la raíz.
- **Aislamiento de datos y medios**: episodios, páginas, redes, tokens y estadísticas quedan asociados a su podcast; audios e imágenes se organizan por directorio y las URLs antiguas de episodios redirigen al podcast destacado.
- **Creación y borrado seguros**: se validan slugs reservados y rutas ocupadas; antes de borrar un podcast se genera una copia ZIP consistente de la base de datos y sus medios.
- **Multipodcast internacionalizado**: todas las cadenas nuevas están traducidas a los ocho idiomas disponibles y cubiertas por pruebas de catálogo.
- **Administración estable con SQLite**: el panel evita solapar cursores de lectura con la transacción diaria de comprobación de actualizaciones, impidiendo que una entrada autenticada bloquee el resto de la web.
- **Activación más clara**: el control Multipodcast mantiene juntos el checkbox y su etiqueta, y muestra un aviso destacado sobre el cambio de URLs y el comportamiento de la portada.
- **Acceso e identidad Multipodcast**: la gestión pasa a `multipodcast.php`, el menú adopta ese nombre y la portada-resumen incorpora un hero propio.
- **Hero configurable del resumen**: cuando la portada principal muestra todos los podcasts, su imagen hero puede elegirse por URL o subirse desde la gestión Multipodcast, con vista previa, recorte y optimización.
- **Portada-resumen personalizable**: permite elegir título, subtítulo y tema propios; simplifica y reduce el hero y añade separación entre las tarjetas de podcasts.
- **Aviso de activación reversible**: el panel explica en tiempo real qué ocurrirá al activar o desactivar Multipodcast e identifica el podcast principal que permanecerá visible.
- **Podcast principal configurable**: las tarjetas de Multipodcast muestran la portada, permiten elegir qué podcast permanece activo al deshabilitar el modo y reorganizan textos y formularios para evitar solapes.
- **Gestión de imágenes**: reorganizadas las imágenes del podcast y del hero en bloques alineados, con miniaturas diferenciadas, vista previa inmediata y visualización completa sin recortes.
- **Traducciones**: completadas las cadenas de los formularios de episodios y páginas, el hero, el campo explícito, la paginación de estadísticas, los tokens y la documentación de la API, y los selectores de archivos en los ocho idiomas soportados.
- **Selector de archivos**: sustituido el texto nativo del navegador por un control traducible que muestra el archivo seleccionado.
- **Proceso de release**: el changelog mantiene las novedades bajo `Siguiente release` y GitHub Actions valida que ese encabezado se sustituya por la versión del tag y coincida con `APP_VERSION` antes de publicar paquetes o imágenes Docker.

## 1.9.11

- **Docker multi-arquitectura**: el workflow de publicación construye una única imagen compatible con `linux/amd64` y `linux/arm64` mediante QEMU y Docker Buildx, reutiliza la caché de GitHub Actions y obtiene dinámicamente el namespace de GHCR. Mejora basada en la contribución de Damián Muraña y validada originalmente en una Raspberry Pi 4 con ARM64.
- **Contenedor actualizado**: la imagen base oficial pasa de PHP 8.5.8 a PHP 8.5.9 sobre Apache y Debian Trixie, manteniendo la versión fijada para evitar cambios silenciosos entre builds.

## 1.9.10

- **Imágenes alineadas en contenido**: las imágenes flotadas a izquierda o derecha desde el editor conservan su alineación en páginas y episodios mediante clases CSS seguras, sin permitir estilos inline arbitrarios.
- **Hero optimizado**: las imágenes subidas para la cabecera se recortan a proporción panorámica, se limitan a 1720 × 720 px y se comprimen como WebP o JPEG utilizando GD, sin incorporar dependencias nuevas.
- **Novedades con formato**: la pantalla posterior a una actualización interpreta de forma segura negritas, cursivas, código y enlaces Markdown del changelog.
- **Aviso diario de actualizaciones**: el panel consulta GitHub una sola vez al día para toda la instalación y muestra un enlace a `update.php` cuando detecta una versión nueva.

## 1.9.9

- **Tema Corporate**: nueva apariencia clara y editorial basada en eduardocollado.com, con acento rojo, tipografía clásica para titulares y adaptación completa a la web pública y al panel.
- **Hero configurable**: la gestión del podcast permite definir o subir una imagen de cabecera opcional, oscurecida para mantener el texto blanco legible y sin modificar las dimensiones actuales.
- **Bloques de código**: las líneas largas se desplazan horizontalmente dentro del bloque sin ensanchar la columna ni desmontar la página.

## 1.9.8

- **Importación remota segura**: los feeds, imágenes y audios tienen límites explícitos de tamaño, las descargas fijan la IP pública validada para impedir DNS rebinding y los archivos se guardan con una extensión inerte hasta confirmar su MIME real.
- **Restauración de medios reforzada**: los ZIP rechazan path traversal, enlaces y tipos no permitidos, validan el MIME antes de publicar cada archivo y limitan entradas, tamaño descomprimido y ratio de compresión para evitar ZIP bombs.
- **Autenticación concurrente**: el límite de intentos de contraseña y TOTP reserva cada intento bajo bloqueo exclusivo, cerrando carreras que podían permitir comprobaciones simultáneas adicionales.
- **XSS y aislamiento del webroot**: el JSON-LD neutraliza cierres de etiquetas y caracteres HTML; Apache bloquea el acceso a `tests/` y la ejecución de archivos PHP o PHAR dentro de `images/` y `audios/`.
- **Documentación y calidad**: documentados en el README los límites de importación de feeds, imágenes, audios y ZIP, con nuevas pruebas de regresión para todos los endurecimientos.

## 1.9.7

- **Tamaño del audio**: el formulario de episodios muestra y acepta el tamaño en MB con dos decimales, conservando internamente los bytes exactos requeridos por el RSS. Cambio realizado siguiendo las recomendaciones de Álex Ávalos.

## 1.9.6

- **Nueva identidad visual**: añadido el tema EasyPodcast, basado en la paleta azul marino, verde petróleo y ámbar de easypodcast.eu, y establecido como predeterminado mediante la migración `v18` sin alterar otros temas elegidos.
- **Protección de imágenes**: al borrar un episodio desde el panel o la API, su imagen solo se elimina físicamente si ya no está referenciada por otros episodios ni como portada general del podcast.
- **Calidad**: añadidos tests de regresión para cubrir imágenes compartidas, portadas y ficheros realmente huérfanos.

## 1.9.5

- **Preescucha del grabador**: la grabación puede escucharse antes de usarla mediante Web Audio, evitando el fallo de reproducción de `Blob` de `MediaRecorder` observado en Firefox para Android y escritorio.
- **Compatibilidad de formatos**: el grabador conserva el MIME real generado por cada navegador al decodificar el audio, en lugar de asumir siempre `audio/webm`.
- **Fotos desde móvil**: las imágenes JPEG aplican físicamente su orientación EXIF al subirlas, evitando que las fotografías tomadas desde `add_episode.php` aparezcan giradas.
- **Caché de recursos**: `add_episode.js` incorpora versionado automático para que las correcciones del grabador se carguen inmediatamente después de actualizar.

## 1.9.4

- **Borradores más flexibles**: los episodios en estado `draft` pueden guardarse únicamente con título; el contenido y los datos de audio siguen siendo obligatorios antes de publicar o programar.
- **Actualizador seguro**: las releases generan un paquete `EasyPodcast-{version}.tar.gz` y su checksum SHA-256; el actualizador web vuelve a consultar GitHub, valida estrictamente las URLs y comprueba la integridad antes de extraer cualquier archivo.
- **Dependencias y contenedor**: imagen Docker actualizada a PHP 8.5.8 y Jodit actualizado de forma coordinada en JavaScript y CSS a 4.13.8, con checksums de los assets incluidos en el repositorio.
- **Automatización de seguridad**: activadas las alertas y actualizaciones de seguridad de Dependabot, añadida revisión semanal de Docker y GitHub Actions, y actualizado `actions/checkout` a v7.
- **Compatibilidad y calidad**: corregida la generación del feed cuando falta la descripción del podcast, reforzados los fixtures SQLite y añadidas pruebas específicas para el actualizador verificable.
- **Documentación**: README ampliado con instalación, arquitectura, esquema de datos, requisitos y medidas de seguridad actualizadas.

## 1.9.3

- **Publicación programada**: reforzada la publicación automática en modo lazy para que los episodios `scheduled` vencidos pasen a `published` también al cargar portada, búsqueda, ficha pública y listado admin, con tests de regresión específicos.
- **Apariencia**: el modo `Según sistema` pasa a ser una preferencia global gestionada por el administrador desde la tarjeta **Apariencia**; se elimina el selector público de la cabecera y se añade la migración `v17` para guardar el ajuste en base de datos.
- **Documentación**: README y changelog actualizados para reflejar la nueva configuración global de apariencia y la nueva migración de esquema.

## 1.9.2

- **Actualizador**: `update.php` recomienda hacer una copia de seguridad antes de actualizar y añade acceso directo a `backups.php`.
- **Traducciones**: añadidas las nuevas cadenas del actualizador en catalán, alemán, inglés, español, francés, gallego, italiano y portugués.

## 1.9.1

- **Publicación programada**: corregida la comparación horaria del scheduler para usar la hora local de PHP en lugar del UTC de SQLite, evitando retrasos al publicar episodios programados.
- **Vista previa**: los administradores ya pueden abrir la vista previa de episodios `scheduled` desde `episodes_management.php` sin error 404.
- **Calidad**: añadidos tests específicos para la vista previa admin de episodios programados y para la publicación automática según hora local.

## 1.9.0

- **Seguridad**: CSP con nonce por petición, endurecimiento de cabeceras base, cookies de sesión más seguras, limitación de intentos de login/TOTP y cierre de sesión del panel protegido por CSRF.
- **Audio y feed**: si la duración del MP3 no llega desde el navegador al guardar un episodio, el backend ahora la calcula desde el audio local para mostrarla en la ficha pública y reutilizarla al regenerar `feed.xml`.
- **API y tokens**: los tokens API dejan de guardarse en claro; la migración `v16` los convierte a `token_hash` + sufijo visible y añade alcances `content` y `admin`.
- **Panel y frontend**: scripts de administración y frontend extraídos a ficheros JS dedicados, mejor soporte responsive en la tabla de tokens API y selector público de tema `Normal | Según sistema` más consistente.
- **Documentación y calidad**: documentación de API/README actualizada, traducciones revisadas y nuevos tests para CSP, CSRF, helpers API y migración de tokens.

## 1.8.11

- **API de estadísticas**: ampliados los datos devueltos para estadísticas diarias, mensuales, anuales y resumen, con soporte de paginación y filtros coherente con el panel.
- **Apariencia**: el tema automático se muestra como `Normal | Según sistema` y se aplica de forma consistente en páginas públicas y de error.
- **Mantenimiento**: actualización de traducciones, documentación de API y limpieza de reglas de `.gitignore`.

## 1.8.10

- **Estadísticas**: las pestañas `Diario`, `Mensual`, `Anual` y `Resumen` ahora paginan los resultados de 100 en 100 filas para evitar tablas demasiado largas en el panel.
- Se añadieron controles de paginación con contador de rango visible y conservación de filtros/pestaña activa al navegar entre páginas.
- Validación automática de números de página y tests específicos para la lógica de paginación de estadísticas.

## 1.8.9

- **RSS y estadísticas**: las descargas iniciadas desde lectores RSS ahora pasan por `track.php`, se contabilizan en estadísticas como tipo `Feed` y el `enclosure` del feed redirige correctamente al MP3 real.
- Corrección de compatibilidad con lectores de feeds: el endpoint de tracking del RSS ya no devuelve JSON como destino del `enclosure`, evitando errores de "archivo multimedia no válido".
- Ajuste de métricas: las peticiones `HEAD` usadas por algunos agregadores para validar el audio ya no incrementan las estadísticas.

## 1.8.8

- **Publicación**: corregido el workflow de release para que solo incluya `docker-compose.yml` en los assets de la release, eliminando los archivos redundantes `EasyPodcast-{version}-source.*`. Ahora las releases contienen solo: `docker-compose.yml`, `Source code (zip)` y `Source code (tar.gz)`.

## 1.8.7

- **Corrección**: activado el workflow de GitHub Actions para construir y publicar automáticamente la imagen Docker en `ghcr.io/educollado/easypodcast` al crear tags y releases. Previamente el workflow solo se activaba al publicar una release manualmente en GitHub, ahora también se activa al hacer `git push` de tags con formato `v*` (ej: `v1.8.7`).

## 1.8.6

- **Estadísticas de descargas y reproducciones**: nueva funcionalidad completa para tracking de descargas y plays de episodios. Incluye pestañas en `stats.php` (Diario, Mensual, Anual, Resumen) con tablas interactivas, filtrado por año y ordenación por columna al hacer clic en las cabeceras.
- Tracking de reproducciones (play) además de descargas, con diferenciación visual en la interfaz.
- Ordenación ascendente/descendente de tablas de estadísticas por cualquier columna (fecha, título, tipo, descargas, año, total).
- Filtrado de estadísticas mensuales por año seleccionable desde dropdown.
- Persistencia de pestaña activa al filtrar por año en estadísticas mensuales.
- Compatibilidad retroactiva con bases de datos sin columna `action_type` en la tabla `download_stats`.
- Corrección en consulta SQL para manejar valores nulos de `action_type` y verificación adecuada en `stats.php`.
- Traducciones completas para todas las cadenas de estadísticas de descargas (diario, mensual, anual, resumen, headers de tabla, filtros, mensajes de vacío).
- Traducciones para el botón "Borrar caché web".
- Corrección: al borrar caché desde el panel, solo se eliminan ficheros `.cache` de la caché web, no las imágenes generadas.
- Inclusión de `scheduledEpisodes` y `totalScheduled` en el return compact() de `episodes_management_handler.php`.
- Eliminación de `PRAGMA user_version` duplicado en migraciones.
- Actualización de documentación y manejador de episodios.

## 1.8.0

- Publicación programada de episodios (sin cron): nuevo estado `scheduled` que permite fijar una fecha y hora futura de publicación. El episodio se publica automáticamente en la siguiente petición web cuando la fecha llega, sin necesidad de configurar cron en el servidor.
- El link canónico del episodio se genera al programarlo, garantizando que la URL no cambia al publicarse.
- Nueva sección "Capítulos Programados" en `episodes_management.php`, ordenada por fecha de publicación ascendente.

## 1.7.1

- Corrección de migración de base de datos al actualizar desde versiones donde `user_version` ya era 12 pero la columna `podcast.admin_theme` no existía: la nueva migración v13 detecta y añade la columna de forma idempotente, resolviendo el error 500 al cambiar de tema.

## 1.7.0

- Selector de temas visuales: el administrador elige el tema desde el panel (tarjeta **Apariencia**) y se aplica a toda la web —panel y páginas públicas— sin JavaScript ni parpadeo (server-side via `data-theme` en `<html>`).
- 9 temas incluidos: Amber Parchment (por defecto), Ember Noir, Arctic Tide, Crimson Dusk, Frost Haven, Matrix Core, Monokai, Pink Essence y Silver Void.
- El tema se almacena en `podcast.admin_theme` (migración v12).
- Eliminado el toggle de modo oscuro por localStorage; la apariencia del sitio la controla el administrador.

## 1.6.4

- Corrección del actualizador interno en Docker: `.htaccess` queda excluido del tarball de release (`export-ignore`) para que el updater nunca lo sobreescriba; se eliminaba el redirect HTTPS desactivado por el entrypoint, causando un bucle de redirección infinito tras cada actualización.
- `performUpdate` crea el fichero de señal `docker/.disable_https_redirect` al actualizar si `DISABLE_HTTPS_REDIRECT=true`, garantizando que el redirect no se activa aunque el contenedor no se haya reiniciado.

## 1.6.3

- La grabación de audio desde el micrófono en `add_episode.php` ya no se pierde si hay un error de validación al enviar el formulario: al pulsar "Usar esta grabación" el audio se sube inmediatamente al servidor vía AJAX (`upload_audio_ajax.php`) y los campos `audio_url`, MIME, tamaño y duración se rellenan automáticamente.
- La URL del capítulo se genera automáticamente mientras el usuario escribe el título, sin necesidad de pulsar el botón "Generar URL".
- Tras aplicar una actualización desde `update.php`, se muestran las novedades de la versión recién instalada leídas desde `CHANGELOG.md`.

## 1.6.2

- Corrección de migración de base de datos al actualizar desde versiones anteriores a 1.6.0: `migration_v9` ahora crea la tabla `api_tokens` si no existe, evitando el error fatal que dejaba la instalación rota tras la actualización.

## 1.6.1

- Opción "Recordar este dispositivo durante 7 días" en el paso 2FA: cookie firmada con HMAC-SHA256 usando el `totp_secret`; se invalida automáticamente al desactivar o resetear el 2FA o al hacer logout.
- Grabador de audio desde micrófono en `add_episode.php`: captura, codificación MP3 en cliente con lamejs y botones con animación de pulso durante la grabación.

## 1.6.0

- API REST con autenticación por token Bearer: endpoints para episodios, podcast, páginas, redes sociales, estadísticas, caché y feed; gestión de tokens desde `api_tokens.php` y documentación en `api_docs.php`.
- Campo `short_description` en episodios: texto breve para portada y extractos en el feed RSS (migración v10).
- Editor HTML Jodit en sustitución del editor Markdown en `add_episode.php`: edición visual con compatibilidad completa de HTML.
- Descripción de episodios almacenada como HTML en columna `content` (renombrada desde `description`, migración v11).
- Renderizado HTML de la descripción en la página de episodio.
- Feed RSS: contenido completo del episodio en `content:encoded`.

## 1.5.1

- Añadidos locales de alemán (`de_DE`) e italiano (`it_IT`).
- Corrección de traducción alemana: "Inicio" → "Startseite".
- Completadas las cadenas i18n de `import_feed.php` y `import_feed_handler.php`.

## 1.5.0

- Internacionalización (i18n) del panel de administración: todos los textos del panel pasan por `__()` para soporte multiidioma.
- Selector de idioma en el panel de administración y en `podcast_management.php`: permite cambiar el idioma de la interfaz sin reiniciar.
- Mejora de rendimiento: resolución de episodios por `link` indexado con fast path O(log n).
- Corrección de rutas de episodio/páginas y validación de padre propio.
- Corrección de resolución de episodios con URL completa en el fast path.

## 1.2.3

- `import_feed.php`: aviso visible si cURL no está disponible en el servidor en lugar de fallo silencioso.
- `README.md`: documentación de los requisitos de cURL para la importación de feeds.

## 1.2.2

- Importación de feed RSS externo (`import_feed.php`): descarga episodios (audio e imágenes) desde cualquier URL de feed, con previsualización y selección individual de episodios y metadatos del podcast.
- Primera importación: si la tabla `podcast` está vacía, se crea la fila de canal automáticamente desde el feed.
- La URL principal (`podcast.link`) nunca se sobrescribe con el valor del feed; se usa siempre el host actual de la instalación.
- `podcast_management.php`: el campo "URL principal" se rellena automáticamente con el host actual cuando está vacío.
- Enlace a "Importar feed" añadido en el panel de administración y en la barra de navegación admin.

## 1.2.1

- Corrección de versión: `APP_VERSION` actualizado a `1.2.1`.

## 1.2

- Imagen Docker oficial publicada en `ghcr.io/educollado/easypodcast`.
- `Dockerfile`: corregidas dependencias del sistema para compilar `pdo_sqlite` y `gd` en Debian Bookworm (`libsqlite3-dev`, `pkg-config`, `libjpeg-dev`).
- `docker/entrypoint.sh`: inicialización automática de la base de datos desde `schema.sql` en primera instalación.
- `docker/init_db.php`: script de inicialización de BD para contenedores nuevos.
- GitHub Actions (`docker-publish.yml`): publicación automática de imagen en cada release.

## 1.1

- Enlace de EasyPodcast en el pie de página apunta ahora a [easypodcast.eu](https://www.easypodcast.eu) en lugar del repositorio de GitHub.

## 1.0

- Páginas estáticas gestionables desde el panel de administración (`pages_management.php`, `add_page.php`): jerarquía padre/hijo, rutas amigables `/slug` y `/padre/hijo`, contenido en Markdown con soporte de imágenes inline y flotadas.
- Barra de navegación pública con submenús CSS puro en `header.php` para mostrar las páginas publicadas.
- Migración de BD v6: nueva tabla `pages` con índice `idx_pages_status`.
- Actualizador integrado (`update.php`): comprueba e instala actualizaciones desde GitHub Releases directamente desde el panel de administración.
- Soporte de imágenes en Markdown (inline, flotadas izquierda/derecha) en `renderMarkdown()` de `lib/view_helpers.php`.

## 0.9

- Autenticación en dos pasos (2FA) con TOTP compatible con Google Authenticator y cualquier app TOTP. Incluye generación de QR, códigos de recuperación (8 por activación) y verificación en el flujo de login.
- Nueva página de gestión de redes sociales (`social_management.php`): blog, LinkedIn, Mastodon, X, Pixelfed, Instagram, YouTube, GitHub y Bluesky. Los enlaces configurados aparecen como iconos SVG en el pie de página público.
- Enlace Mastodon en el footer con `rel="me"` tomado de la BD en lugar de ser estático.
- Nueva página de gestión de caché (`cache_management.php`) con habilitación/deshabilitación, borrado y regeneración de imágenes; estos controles se han extraído de `podcast_management.php`.
- Nueva página de cambio de contraseña (`change_password.php`) accesible desde el panel de administración.
- Nueva página de estadísticas (`stats.php`): episodios publicados, borradores, último publicado, tamaño total de audios y estado detallado de la caché (activa/inactiva, número de páginas cacheadas y tamaño).
- Migraciones de BD v4 (columnas TOTP en `management`) y v5 (tabla `social`).

## 0.8

- Rediseño visual completo del frontend público (`index.php`, `episode.php`, `search.php`, `header.php` y CSS asociados).
- Rediseño visual del panel de administración con navegación compartida (`admin_nav.php`) y estilos comunes (`assets/css/admin-common.css`).
- `robots.txt` pasa a servirse de forma dinámica desde `robots.php` (rewrite en `.htaccess`) usando el dominio configurado en `podcast.link`.
- Footer público extraído a parcial reutilizable (`footer.php`) con enlace `rel="me"` a Mastodon para verificación.
- Sustitución de iconografía raster/emojis por SVG inline (icono RSS y toggle de tema).
- Feed RSS: limpieza de sintaxis Markdown en descripciones para publicar texto plano.

## 0.7

- Modo oscuro con toggle 🌙/☀️ en la cabecera pública: preferencia guardada en `localStorage` del navegador, sin cambios en BD.
- Script anti-FOUC en `<head>` para aplicar el tema antes del primer render y evitar parpadeo.
- CSS de modo oscuro en `assets/css/dark.css` con `html[data-theme="dark"]` (especificidad superior a `:root`).
- Reordenación del contenido en la página de episodio: título → fecha → reproductor → metadatos → descripción.
- Nuevo formato de metadatos de audio: `Descargar (Duración: X — Y MB)`.
- Pie de página actualizado: *EasyPodcast, made in **Europe** with ❤️ by Eduardo Collado*.

## 0.6

- Editor Markdown con vista previa en tiempo real para la descripción de episodios en administración (`lib/markdown_editor.php`).
- Renderizado de Markdown en la web pública: descripción de episodio en detalle y en el feed RSS.
- Extractos de portada sin sintaxis Markdown (se limpia antes de mostrar el resumen).
- Sistema centralizado de migraciones de BD basado en `PRAGMA user_version` (`lib/migration_runner.php`); la versión actual es 1.
- Refactorización de `index.php`, `episode.php` y las páginas de administración: separación de lógica y presentación.
- PHPDoc y comentarios inline añadidos en todos los ficheros PHP.

## 0.5

- Refactorización de `add_episode.php`: la lógica POST (~350 líneas) se extrae a dos nuevas librerías, dejando el controlador con ~280 líneas.
- Nuevo `lib/upload_service.php`: subida de imagen y audio del episodio, escritura de tags ID3 inmediatamente tras subida y reescritura de metadatos en edición.
- Nuevo `lib/episode_save_handler.php`: validación pura del formulario (`validateEpisodeForm`), carga de defaults del podcast con migración no destructiva (`loadPodcastDefaults`), inicialización del formulario (`episodeFormDefaults`) y orquestación completa del guardado (`saveEpisode`).
- Protección CSRF en todos los formularios de administración (`lib/csrf.php`): generación de token con `csrf_token()` y verificación con `csrf_verify()`.
- Suite de tests ampliada a 107 tests (24 nuevos que cubren `validateEpisodeForm` y `episodeFormDefaults` sin dependencias de BD ni ficheros).

## 0.4

- Nuevo `sitemap.xml` estático con regeneración automática en cambios de administración.
- Nuevo `robots.txt` con reglas de rastreo y referencia al sitemap.
- Nuevo sistema de caché pública en `cache/` (`lib/cache_service.php`) para portada, detalle, feed y sitemap.
- Opción en `podcast_management.php` para habilitar/deshabilitar caché (`cache_enabled`).
- Nuevo botón en `podcast_management.php` para borrar caché manualmente.
- Invalidación automática de caché tras cambios en administración (guardar podcast, crear/editar/borrar episodios, importaciones de backups).
- Portada y detalle de episodio ahora usan `/favicon.ico` para evitar descargar imágenes grandes como icono.
- Miniaturas responsive unificadas a variantes `144x144` y `220x220` en portada y detalle.
- `schema.sql` actualizado con la columna `cache_enabled` en tabla `podcast`.

## 0.3

- Nueva página `backups.php` para copias de seguridad, separada del panel principal.
- Exportación de base de datos SQLite desde administración.
- Importación de base de datos con validación y backup previo en `backups/`.
- Exportación de ficheros `images/` y `audios/` en ZIP descargable temporal.
- Importación de ZIP de ficheros con validaciones de seguridad de rutas.
- Reorganización visual en bloques separados: **Base de Datos** y **Ficheros**.
- Mejoras responsive en portada, detalle de episodio y gestión de capítulos.
- Nuevo botón **Visitar podcast** en el panel de administración.
- Generación automática de `favicon.ico` al guardar metadatos del podcast.

## 0.2

- Añadida escritura de metadatos ID3 directamente en ficheros MP3 desde la administración.
- Nueva opción en Gestión Podcast para activar/desactivar la escritura automática de metadatos.
- Soporte de actualización manual de metadatos en episodios ya existentes.
- Inclusión de portada en etiquetas ID3 (imagen de episodio o fallback a imagen del podcast).
- Refactor de `add_episode.php` separando utilidades en `lib/episode_helpers.php` e `lib/id3_service.php`.
- Helpers de vista comunes extraídos a `lib/view_helpers.php`.
