# EasyPodcast

> Aplicación en **PHP + SQLite** para publicar un podcast con web pública, páginas de episodio y feed RSS.

## Versión

**Versión actual: 1.2.3**

## Novedades 1.2.3

- `import_feed.php`: aviso visible si cURL no está disponible en el servidor en lugar de fallo silencioso.

## Novedades 1.2.2

- Importación de feed RSS externo: descarga episodios (audio e imágenes) desde cualquier URL de feed con previsualización y selección individual.
- Primera importación: crea automáticamente la fila del podcast si la BD está vacía.
- La URL principal nunca se sobreescribe con el valor del feed; usa siempre el host de la instalación.
- `podcast_management.php`: el campo "URL principal" se rellena automáticamente con el host actual cuando está vacío.

## Novedades 1.2

- Imagen Docker oficial publicada en `ghcr.io/educollado/easypodcast` con GitHub Actions.
- Instalación con Docker en un solo comando usando `docker-compose.yml`.
- Inicialización automática de la base de datos en primera instalación.

## Novedades 1.1

- Enlace de EasyPodcast en el pie de página apunta ahora a [easypodcast.eu](https://www.easypodcast.eu) en lugar del repositorio de GitHub.

## Novedades 1.0

- Páginas estáticas con jerarquía padre/hijo, rutas amigables y contenido Markdown (imágenes inline y flotadas).
- Barra de navegación pública con submenús CSS puro para mostrar las páginas publicadas.
- Actualizador integrado: comprueba e instala actualizaciones desde GitHub Releases directamente desde el panel.

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
| SEO/Indexación | `robots.txt` dinámico + `sitemap.xml` estático regenerable |
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
  - reproductor inline
  - enlace de descarga con duración y tamaño: `Descargar (Duración: X — Y MB)`
  - descripción completa renderizada como Markdown
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
- `robots.txt` dinámico servido por `robots.php` (rewrite en `.htaccess`) con `Sitemap` calculado desde `podcast.link`.
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
| `add_episode.php` | Alta/edición de episodios |
| `backups.php` | Exportar/importar base de datos y ficheros (`images/`, `audios/`) |
| `cache_management.php` | Habilitar/deshabilitar caché, borrar y regenerar imágenes |
| `twofa_management.php` | Activar/desactivar 2FA TOTP y gestionar códigos de recuperación |
| `social_management.php` | Gestión de enlaces a redes sociales |
| `change_password.php` | Cambio de contraseña del administrador |
| `stats.php` | Estadísticas de episodios y caché |
| `pages_management.php` | Gestión de páginas estáticas |
| `add_page.php` | Alta/edición de páginas estáticas |
| `update.php` | Comprobación e instalación de actualizaciones desde GitHub Releases |
| `import_feed.php` | Importación de episodios desde un feed RSS externo (previsualización, selección y descarga de audio e imágenes) |

## Estructura del proyecto

| Ruta | Descripción |
|---|---|
| `index.php` | Portada pública |
| `episode.php` | Página pública de episodio |
| `search.php` | Página pública de búsqueda de episodios |
| `header.php` | Cabecera pública compartida (título/autor/descripción + RSS + buscador) |
| `footer.php` | Pie público compartido para páginas públicas |
| `feed.php` | Endpoint RSS dinámico |
| `sitemap.xml` | Sitemap estático regenerado automáticamente |
| `lib/sitemap_builder.php` | Constructor y escritura de `sitemap.xml` |
| `feed_builder.php` | Constructor de RSS + escritura de `feed.xml` |
| `feed.xml` | Feed generado |
| `robots.php` | Generador dinámico de `robots.txt` |
| `admin.php` | Panel de administración |
| `admin_nav.php` | Navegación compartida del panel admin |
| `podcast_management.php` | Gestión del canal |
| `episodes_management.php` | Gestión de episodios |
| `backups.php` | Copias de seguridad de base de datos y ficheros |
| `add_episode.php` | Alta/edición de episodios y subida de audio/imagen (editor Markdown con barra de herramientas) |
| `canonical_redirect.php` | Redirección 301 al host/esquema canónico definido en `podcast.link` |
| `lib/episode_helpers.php` | Utilidades de episodios (fechas, slug, rutas, MIME) |
| `lib/episode_save_handler.php` | Validación de formulario, persistencia BD y efectos post-guardado de episodios |
| `lib/upload_service.php` | Subida de imagen/audio del episodio y escritura de metadatos ID3 tras subida |
| `lib/id3_service.php` | Escritura de metadatos ID3 para MP3 |
| `lib/seo_helpers.php` | Helpers SEO (`canonical`, URLs absolutas, `meta description`) |
| `lib/view_helpers.php` | Helpers compartidos de vista (`esc`, enlaces, slug, fechas) |
| `lib/public_episode_helpers.php` | Resolución de rutas y slugs para episodios públicos |
| `lib/cache_service.php` | Servicio de caché (lectura/escritura/limpieza) |
| `lib/csrf.php` | Protección CSRF para formularios de administración |
| `lib/search_query.php` | Consulta SQL y paginación de búsqueda (`search.php`) |
| `lib/search_seo.php` | Metadatos SEO de la página de búsqueda |
| `lib/episodes_management_query.php` | Consultas SQL y acciones CRUD de gestión de episodios |
| `lib/add_episode_query.php` | Orquestación del formulario de alta/edición de episodio |
| `import_feed.php` | Importación de feed RSS externo |
| `lib/import_feed_handler.php` | Parser de feed, descarga de audios/imágenes y lógica de importación con streaming de progreso |
| `lib/podcast_management_handler.php` | Acciones POST de gestión del canal (metadatos, caché, favicon) |
| `lib/admin_query.php` | Autenticación: login, setup inicial y logout |
| `lib/backup_handler.php` | Exportación/importación de base de datos y ficheros multimedia |
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
| Extensiones | `pdo_sqlite`, `sqlite3`, `fileinfo`, `xmlwriter`, `zip`, `gd`, `curl` |
| Servidor | Apache con `mod_rewrite` |
| Permisos de escritura | `podcast.sqlite`, `feed.xml`, `audios/`, `images/`, `cache/`, `favicon.ico` |

## Instalación

### Con Docker (recomendado)

```bash
# 1. Crear directorios de datos
mkdir -p data/db data/audios data/images data/cache

# 2. Levantar el contenedor
docker run -d \
  --name easypodcast \
  -p 8080:80 \
  -e PODCAST_DB_PATH=/var/www/html/data/podcast.sqlite \
  -v $(pwd)/data/db:/var/www/html/data \
  -v $(pwd)/data/audios:/var/www/html/audios \
  -v $(pwd)/data/images:/var/www/html/images \
  -v $(pwd)/data/cache:/var/www/html/cache \
  ghcr.io/educollado/easypodcast:latest
```

O con `docker compose up -d` usando el `docker-compose.yml` del repositorio.

La base de datos se inicializa automáticamente en el primer arranque.

---

### Con Apache (instalación manual)

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
| `management` | Credenciales de administración y configuración 2FA (TOTP) |
| `social` | Enlaces a redes sociales del autor (una sola fila) |
| `pages` | Páginas estáticas con jerarquía padre/hijo |

Ver detalle en `schema.sql`.

### Migraciones de esquema

El sistema usa `PRAGMA user_version` de SQLite para versionar el esquema sin tablas extra. Al arrancar cada request, `lib/migration_runner.php` comprueba la versión actual y aplica sólo las migraciones pendientes. Las instalaciones nuevas parten ya con `user_version = 1` gracias a `schema.sql`; las existentes se actualizan automáticamente en el primer acceso.

#### Cómo añadir una nueva migración

Edita `lib/migration_runner.php` y haz dos cosas:

**1. Añade el bloque condicional en `runMigrations()`:**

```php
if ($version < 2) {
    migration_v2($pdo);
    $pdo->exec('PRAGMA user_version = 2');
}
```

**2. Implementa la función de migración:**

```php
function migration_v2(PDO $pdo): void
{
    // Ejemplo: crear una tabla nueva
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS episode_tags (
            episode_id INTEGER NOT NULL,
            tag TEXT NOT NULL,
            PRIMARY KEY (episode_id, tag),
            FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE
        )
    ');

    // Ejemplo: añadir una columna nueva a una tabla existente
    $existing = array_column(
        $pdo->query('PRAGMA table_info(episodes)')->fetchAll(),
        'name'
    );
    if (!in_array('nueva_columna', $existing, true)) {
        $pdo->exec('ALTER TABLE episodes ADD COLUMN nueva_columna TEXT');
    }
}
```

> Para columnas nuevas en tablas existentes, comprueba siempre con `PRAGMA table_info` antes del `ALTER TABLE` para que la migración sea idempotente.

**3. Actualiza también `schema.sql`** para que las instalaciones nuevas ya incluyan los cambios y el `PRAGMA user_version` refleje la versión más reciente:

```sql
PRAGMA user_version = 2;
```

#### Historial de versiones

| Versión | Cambios |
|---|---|
| 1 | Añade `rss_item_limit`, `home_items_per_page`, `write_audio_metadata`, `cache_enabled` a `podcast` |
| 3 | Hace `pub_date` nullable en `episodes` (permite borradores sin fecha) |
| 4 | Añade columnas `totp_secret`, `totp_enabled`, `totp_recovery_codes` a `management` |
| 5 | Crea tabla `social` |
| 6 | Crea tabla `pages` con índice `idx_pages_status` |

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
| `assets/css/common.css` | Estilos base compartidos de la parte pública |
| `assets/css/index.css` | Portada pública |
| `assets/css/episode.css` | Página de episodio |
| `assets/css/header.css` | Cabecera pública compartida (index/episode/search) + botón modo oscuro |
| `assets/css/dark.css` | Modo oscuro (variables y fondo; cargado el último para ganar en cascada) |
| `assets/css/admin-common.css` | Estilos base compartidos del área de administración |
| `assets/css/admin.css` | Login/panel admin |
| `assets/css/podcast_management.css` | Gestión podcast |
| `assets/css/episodes_management.css` | Gestión episodios |

## Editor de descripción (Markdown)

El campo descripción de cada episodio usa [EasyMDE](https://github.com/Ionaru/easy-markdown-editor) como editor con barra de herramientas. El contenido se guarda como Markdown en la base de datos y se renderiza a HTML en la página pública del episodio.

Markdown soportado en el editor y renderizado:

- `**negrita**` → **negrita**
- `*cursiva*` → *cursiva*
- `# Título`, `## Subtítulo`, `### Sub`
- `- item` o `* item` → lista con viñetas
- `1. item` → lista numerada
- `[texto](https://...)` → enlace

El renderizado lo realiza `renderMarkdown()` en `lib/view_helpers.php` (PHP puro, sin dependencias externas). El feed RSS almacena el Markdown en texto plano.

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
