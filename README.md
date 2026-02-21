# EasyPodcast

> Aplicación ligera en **PHP + SQLite** para publicar un podcast con web pública, páginas de episodio y feed RSS.

## Versión

**Versión actual: 0.5**

## Sitio de referencia

| Campo | Valor |
|---|---|
| URL | **https://www.aratospodcast.com** |
| Tipo de proyecto | Podcast personal del autor |

## Resumen rápido

| Área | Qué incluye |
|---|---|
| Web pública | Portada con episodios `published`, página individual por episodio, reproductor inline |
| Feed | RSS dinámico (`feed.php`) y feed generado (`feed.xml`) |
| SEO/Indexación | `robots.txt` y `sitemap.xml` estático regenerable |
| Caché | Caché pública en `cache/` (configurable desde administración) |
| Administración | Login, gestión del canal, alta/edición/borrado de episodios, copias de seguridad y metadatos ID3 |
| Subidas | Audio a `audios/`, imágenes a `images/` |
| Estilos | CSS separado por página en `assets/css/` |

## Funcionalidades

### Parte pública

- Portada (`index.php`) con:
  - episodios publicados
  - paginación configurable desde administración (`home_items_per_page`)
  - portada del episodio (o fallback a portada del podcast)
  - extracto y reproductor
  - si el extracto se recorta, se muestra `[Leer más]` enlazado al episodio completo
  - buscador de episodios en cabecera
- Página de episodio (`episode.php`) con:
  - URL amigable `/YYYY/MM/slug`
  - descripción completa
  - duración, tamaño y descarga
  - reproductor inline
  - buscador de episodios en cabecera
- Página de búsqueda (`search.php`) con:
  - búsqueda por título y descripción en episodios `published`
  - paginación de resultados
  - reutiliza la cabecera pública común

### Feed RSS

- `feed.php`: genera RSS en tiempo real.
- `feed_builder.php`: lógica común del feed.
- `feed.xml`: se regenera automáticamente al guardar/editar/borrar desde administración.
- El número de episodios del feed se controla con `rss_item_limit` en `podcast_management.php`:
  - `0` = sin límite
  - `N > 0` = máximo de `N` episodios publicados más recientes
- El número de episodios por página en portada y búsqueda se controla con `home_items_per_page` en `podcast_management.php`:
  - mínimo `1`
  - valor por defecto `20`

### SEO / Indexación

- Redirección canónica 301 de host/esquema según `podcast.link` (en `canonical_redirect.php`).
- Etiquetas SEO en páginas públicas:
  - `canonical`
  - `meta robots` (index/noindex según contexto)
  - `meta description` con recorte automático y fallback
  - Open Graph (`og:type`, `og:title`, `og:description`, `og:url`, `og:image`)
  - `link rel="alternate"` al RSS (`/feed.xml`)
- Paginación SEO:
  - portada y búsqueda incluyen `rel="prev"` / `rel="next"` cuando aplica
  - páginas 2+ de portada se marcan como `noindex,follow`
- Página de búsqueda (`search.php`) marcada como `noindex,follow` y `X-Robots-Tag: noindex, follow, noarchive`.
- En errores de carga pública se envía `X-Robots-Tag: noindex, nofollow, noarchive`.
- Datos estructurados JSON-LD:
  - `PodcastSeries` en portada
  - `PodcastEpisode` en detalle de episodio
- `robots.txt`: reglas de rastreo para buscadores.
- `sitemap.xml`: sitemap XML estático regenerado automáticamente tras cambios en administración.

### Caché

- Caché de páginas/feeds en `cache/`.
- Se activa/desactiva en `podcast_management.php` (`cache_enabled`).
- Botón de borrado manual de caché en `podcast_management.php`.
- Se limpia automáticamente al guardar/borrar/importar datos desde administración.

### Administración

| Página | Función |
|---|---|
| `admin.php` | Login/logout y acceso al panel |
| `podcast_management.php` | Metadatos del podcast (tabla `podcast`) |
| `episodes_management.php` | CRUD de episodios (tabla `episodes`) |
| `backups.php` | Exportar/importar base de datos y ficheros (`images/`, `audios/`) |

## Estructura del proyecto

| Ruta | Descripción |
|---|---|
| `index.php` | Portada pública |
| `episode.php` | Página pública de episodio |
| `search.php` | Página pública de búsqueda de episodios |
| `header.php` | Cabecera pública compartida (título/autor/descripción + RSS + buscador) |
| `feed.php` | Endpoint RSS dinámico |
| `sitemap.xml` | Sitemap estático regenerado automáticamente |
| `lib/sitemap_builder.php` | Constructor y escritura de `sitemap.xml` |
| `feed_builder.php` | Constructor de RSS + escritura de `feed.xml` |
| `feed.xml` | Feed generado |
| `robots.txt` | Reglas para rastreadores web |
| `admin.php` | Panel de administración |
| `podcast_management.php` | Gestión del canal |
| `episodes_management.php` | Gestión de episodios |
| `backups.php` | Copias de seguridad de base de datos y ficheros |
| `add_episode.php` | Alta/edición de episodios y subida de audio/imagen |
| `canonical_redirect.php` | Redirección 301 al host/esquema canónico definido en `podcast.link` |
| `lib/episode_helpers.php` | Utilidades de episodios (fechas, slug, rutas, MIME) |
| `lib/episode_save_handler.php` | Validación de formulario, persistencia BD y efectos post-guardado de episodios |
| `lib/upload_service.php` | Subida de imagen/audio del episodio y escritura de metadatos ID3 tras subida |
| `lib/id3_service.php` | Escritura de metadatos ID3 para MP3 |
| `lib/seo_helpers.php` | Helpers SEO (`canonical`, URLs absolutas, `meta description`) |
| `lib/view_helpers.php` | Helpers compartidos de vista (`esc`, enlaces, slug, fechas) |
| `lib/cache_service.php` | Servicio de caché (lectura/escritura/limpieza) |
| `lib/csrf.php` | Protección CSRF para formularios de administración |
| `schema.sql` | Esquema de base de datos |
| `podcast.sqlite` | Base de datos SQLite |
| `cache/` | Archivos de caché pública generados en runtime |
| `audios/` | Audios subidos |
| `images/` | Imágenes subidas |
| `images/generated/` | Imágenes generadas automáticamente para títulos/ilustraciones de la web |
| `assets/css/` | Hojas de estilo separadas (theming) |
| `.htaccess` | HTTPS + rutas amigables |

## Requisitos

| Componente | Requisito |
|---|---|
| PHP | 8+ recomendado |
| Extensiones | `pdo_sqlite`, `sqlite3`, `fileinfo`, `xmlwriter`, `zip`, `gd` |
| Servidor | Apache con `mod_rewrite` |
| Permisos de escritura | `podcast.sqlite`, `feed.xml`, `audios/`, `images/`, `cache/`, `favicon.ico` |

## Instalación

### 1) Copiar el proyecto al servidor web

Puedes clonar el repositorio o copiar los archivos al `DocumentRoot` de Apache.

### 2) Crear la base de datos SQLite

Desde la raíz del proyecto:

```bash
sqlite3 podcast.sqlite < schema.sql
```

### 3) Crear carpetas de subida (si no existen)

```bash
mkdir -p audios images
```

### 4) Dar permisos de escritura al usuario del servidor web

El usuario de Apache/Nginx (por ejemplo `www-data`) debe poder escribir en:
- `podcast.sqlite`
- `feed.xml`
- `audios/`
- `images/`
- `cache/`
- `favicon.ico`

Ejemplo (ajusta usuario/grupo a tu servidor):

```bash
chown -R www-data:www-data podcast.sqlite feed.xml audios images cache favicon.ico
chmod 775 audios images cache
chmod 664 podcast.sqlite feed.xml favicon.ico
```

### 5) Activar `mod_rewrite` y `.htaccess` en Apache

- Habilita `mod_rewrite`.
- Asegúrate de permitir overrides (`AllowOverride All`) en el virtual host o directorio del sitio.

### 6) Acceso inicial al panel

1. Abre `/admin.php`.
2. Crea el primer usuario administrador.
3. Configura metadatos del podcast en `podcast_management.php`.
4. Crea episodios en `episodes_management.php`.
5. Gestiona copias de seguridad en `backups.php`.

### 7) Comprobaciones finales

- Portada pública: `/`
- Feed dinámico: `/feed.php`
- Feed generado: `/feed.xml`
- Sitemap: `/sitemap.xml`
- Robots: `/robots.txt`

## Base de datos

| Tabla | Uso |
|---|---|
| `podcast` | Metadatos del canal (una sola fila) |
| `episodes` | Episodios y estado de publicación |
| `management` | Credenciales de administración |

Ver detalle en `schema.sql`.

## Flujo de publicación

1. Configura el canal en `podcast_management.php`.
2. Crea episodios en `episodes_management.php`.
3. Marca episodios como `published`.
4. La web pública y el feed solo muestran `published`.
5. El sistema regenera `feed.xml` automáticamente tras cambios.

## Modelo de URLs

| Recurso | URL |
|---|---|
| Portada | `/` |
| Feed dinámico | `/feed.php` |
| Feed generado | `/feed.xml` |
| Sitemap | `/sitemap.xml` |
| Robots | `/robots.txt` |
| Episodio | `/YYYY/MM/slug` |

## Personalización / temas

Para crear temas, modifica o reemplaza los archivos de `assets/css/`.

| CSS | Página asociada |
|---|---|
| `assets/css/index.css` | Portada pública |
| `assets/css/episode.css` | Página de episodio |
| `assets/css/header.css` | Cabecera pública compartida (index/episode/search) |
| `assets/css/admin.css` | Login/panel admin |
| `assets/css/podcast_management.css` | Gestión podcast |
| `assets/css/episodes_management.css` | Gestión episodios |

## Notas

- Si un episodio no tiene imagen, se usa la del podcast.
- Las imágenes generadas automáticamente para títulos/ilustraciones se guardan en `images/generated/`.
- Si se borran imágenes de `images/generated/`, la aplicación puede regenerarlas automáticamente cuando vuelven a ser necesarias.
- El autor del episodio puede heredarse desde la configuración del podcast.
- En RSS, `rss_item_limit = 0` significa sin límite de episodios.
- El MIME del audio en RSS se normaliza para compatibilidad con plataformas.
- Si activas la opción en `podcast_management.php`, al subir/editar MP3 se escriben metadatos ID3 (incluida portada de episodio o fallback de podcast).
- Al guardar metadatos del podcast, se intenta regenerar `favicon.ico` automáticamente usando la imagen del podcast.
- La portada y el detalle de episodio usan `/favicon.ico` como icono del sitio para evitar descargas de imágenes grandes.
- La caché pública se guarda en `cache/` y se invalida automáticamente en cambios de administración.
- En `backups.php`, la exportación de ficheros genera un ZIP temporal para descarga; la importación acepta ZIP con rutas bajo `images/` y `audios/`.

## Licencia (Software Libre)

EasyPodcast es **Software Libre** y se distribuye bajo **GNU GPL v3 o posterior (GPL-3.0-or-later)**.

Consulta `LICENSE` para los términos completos.

