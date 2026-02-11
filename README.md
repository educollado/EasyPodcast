# EasyPodcast

![GitHub](https://img.shields.io/github/license/educollado/EasyPodcast)
![GitHub last commit](https://img.shields.io/github/last-commit/educollado/EasyPodcast)
![GitHub repo size](https://img.shields.io/github/repo-size/educollado/EasyPodcast)
[![Follow on Mastodon](https://img.shields.io/mastodon/follow/1234567890?domain=https%3A%2F%2Fsocial.collado.eu&label=%40edu%40social.collado.eu&logo=mastodon&logoColor=white&style=for-the-badge)](https://social.collado.eu/@edu)


EasyPodcast es una aplicación ligera en **PHP + SQLite** para publicar una web de podcast y su feed RSS sin usar frameworks.

## Sitio de referencia

El sitio de referencia donde se van aplicando los cambios de este proyecto es:

**https://www.aratospodcast.com**

Este sitio corresponde al podcast personal del autor.

## Qué hace el software

EasyPodcast ofrece:
- Portada pública con episodios publicados.
- Página individual por episodio con URL amigable (`/YYYY/MM/slug`).
- Feed RSS para plataformas de podcast.
- Área de administración para gestionar el podcast y los episodios.
- Subida de audios e imágenes.
- Regeneración automática de `feed.xml` cuando se actualizan datos desde administración.

## Funcionalidades principales

### Parte pública
- `index.php` muestra solo episodios con estado `published`.
- Cada episodio muestra:
  - Imagen (la del episodio o fallback a la imagen general del podcast).
  - Título, fecha, extracto y reproductor inline.
- El título del episodio enlaza a su página individual.
- Paginación de 20 episodios por página.
- Acceso al feed desde `/feed.xml`.

### Página individual de episodio
- `episode.php` muestra un episodio publicado.
- URL amigable: `/YYYY/MM/titulo-del-episodio`.
- Muestra descripción completa, reproductor, duración, tamaño y enlace de descarga.

### Feed RSS
- `feed.php` genera RSS dinámico desde la base de datos.
- `feed_builder.php` centraliza la lógica de generación del feed.
- `feed.xml` se regenera automáticamente desde las pantallas de administración.

### Administración
- `admin.php`: acceso/login del panel.
- `podcast_management.php`: gestión de metadatos del canal (`podcast`).
- `episodes_management.php`: alta/edición/borrado de episodios (`episodes`).
- Subidas:
  - Audios a `audios/`
  - Imágenes a `images/`

## Estructura del proyecto

- `index.php`: portada pública
- `episode.php`: página pública de episodio
- `feed.php`: endpoint RSS dinámico
- `feed_builder.php`: constructor del feed y escritura de `feed.xml`
- `feed.xml`: feed generado
- `admin.php`: panel de administración
- `podcast_management.php`: gestión del podcast
- `episodes_management.php`: gestión de episodios
- `schema.sql`: esquema de base de datos
- `podcast.sqlite`: base de datos SQLite
- `audios/`: audios subidos
- `images/`: imágenes subidas
- `assets/css/`: hojas de estilo separadas (facilita crear temas)
- `.htaccess`: HTTPS + rutas amigables

## Requisitos

- PHP 8+ (recomendado).
- Extensiones PHP:
  - `pdo_sqlite`
  - `sqlite3`
  - `fileinfo`
  - `xmlwriter`
- Apache con `mod_rewrite` activo (para URLs amigables).
- Permisos de escritura para:
  - `podcast.sqlite`
  - `feed.xml`
  - `audios/`
  - `images/`

## Base de datos

La aplicación usa SQLite con estas tablas principales:
- `podcast`: metadatos del canal (diseño de una sola fila).
- `episodes`: metadatos y estado de publicación de episodios.
- `management`: credenciales de administración.

Consulta `schema.sql` para el detalle.

## Flujo de publicación

1. Configura los metadatos del podcast en `podcast_management.php`.
2. Crea episodios en `episodes_management.php`.
3. Marca el episodio como `published`.
4. Las páginas públicas y el feed solo incluyen episodios `published`.
5. Al guardar/editar/borrar desde administración, `feed.xml` se regenera automáticamente.

## Modelo de URLs

- Portada: `/`
- Feed dinámico: `/feed.php`
- Feed generado: `/feed.xml`
- Detalle episodio: `/YYYY/MM/slug`

## Temas y estilos

Los estilos están separados por página dentro de `assets/css/`.

Puedes crear temas modificando esas hojas CSS sin tocar las plantillas PHP.

## Notas

- Si un episodio no tiene imagen, se usa la imagen del podcast.
- El autor del episodio puede heredarse automáticamente desde la configuración del podcast.
- El MIME del audio en el feed se normaliza para compatibilidad con plataformas de podcast.

## Licencia (Free Software)

EasyPodcast es **Software Libre**.

Este proyecto se distribuye bajo la licencia **GNU GPL v3 o posterior (GPL-3.0-or-later)**.

Consulta el archivo `LICENSE` para más detalles.
