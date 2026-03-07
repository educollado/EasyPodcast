# Changelog

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
