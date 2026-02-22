# Changelog

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
