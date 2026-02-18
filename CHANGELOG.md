# Changelog

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
