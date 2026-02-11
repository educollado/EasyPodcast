# EasyPodcast

> Aplicación ligera en **PHP + SQLite** para publicar un podcast con web pública, páginas de episodio y feed RSS.

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
| Administración | Login, gestión del canal, alta/edición/borrado de episodios |
| Subidas | Audio a `audios/`, imágenes a `images/` |
| Estilos | CSS separado por página en `assets/css/` |

## Funcionalidades

### Parte pública

- Portada (`index.php`) con:
  - episodios publicados
  - paginación de 20 en 20
  - portada del episodio (o fallback a portada del podcast)
  - extracto y reproductor
- Página de episodio (`episode.php`) con:
  - URL amigable `/YYYY/MM/slug`
  - descripción completa
  - duración, tamaño y descarga
  - reproductor inline

### Feed RSS

- `feed.php`: genera RSS en tiempo real.
- `feed_builder.php`: lógica común del feed.
- `feed.xml`: se regenera automáticamente al guardar/editar/borrar desde administración.

### Administración

| Página | Función |
|---|---|
| `admin.php` | Login/logout y acceso al panel |
| `podcast_management.php` | Metadatos del podcast (tabla `podcast`) |
| `episodes_management.php` | CRUD de episodios (tabla `episodes`) |

## Estructura del proyecto

| Ruta | Descripción |
|---|---|
| `index.php` | Portada pública |
| `episode.php` | Página pública de episodio |
| `feed.php` | Endpoint RSS dinámico |
| `feed_builder.php` | Constructor de RSS + escritura de `feed.xml` |
| `feed.xml` | Feed generado |
| `admin.php` | Panel de administración |
| `podcast_management.php` | Gestión del canal |
| `episodes_management.php` | Gestión de episodios |
| `schema.sql` | Esquema de base de datos |
| `podcast.sqlite` | Base de datos SQLite |
| `audios/` | Audios subidos |
| `images/` | Imágenes subidas |
| `assets/css/` | Hojas de estilo separadas (theming) |
| `.htaccess` | HTTPS + rutas amigables |

## Requisitos

| Componente | Requisito |
|---|---|
| PHP | 8+ recomendado |
| Extensiones | `pdo_sqlite`, `sqlite3`, `fileinfo`, `xmlwriter` |
| Servidor | Apache con `mod_rewrite` |
| Permisos de escritura | `podcast.sqlite`, `feed.xml`, `audios/`, `images/` |

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
| Episodio | `/YYYY/MM/slug` |

## Personalización / temas

Para crear temas, modifica o reemplaza los archivos de `assets/css/`.

| CSS | Página asociada |
|---|---|
| `assets/css/index.css` | Portada pública |
| `assets/css/episode.css` | Página de episodio |
| `assets/css/admin.css` | Login/panel admin |
| `assets/css/podcast_management.css` | Gestión podcast |
| `assets/css/episodes_management.css` | Gestión episodios |

## Notas

- Si un episodio no tiene imagen, se usa la del podcast.
- El autor del episodio puede heredarse desde la configuración del podcast.
- El MIME del audio en RSS se normaliza para compatibilidad con plataformas.

## Licencia (Software Libre)

EasyPodcast es **Software Libre** y se distribuye bajo **GNU GPL v3 o posterior (GPL-3.0-or-later)**.

Consulta `LICENSE` para los términos completos.
