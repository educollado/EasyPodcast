# EasyPodcast

[![Versión](https://img.shields.io/badge/versión-1.9.11-blue)](https://github.com/educollado/EasyPodcast/releases/latest)
[![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)](https://www.sqlite.org/)
[![Docker](https://img.shields.io/badge/Docker-ghcr.io-2496ED?logo=docker&logoColor=white)](https://github.com/educollado/EasyPodcast/pkgs/container/easypodcast)
[![Licencia](https://img.shields.io/badge/licencia-GPL--3.0-green)](LICENSE)

Aplicación en **PHP + SQLite** para publicar un podcast con web pública, feed RSS, SEO y panel de administración. Sin dependencias externas ni composer.

---

## Instalación con instalador automático (Opción recomendada)

La forma más sencilla de instalar EasyPodcast: un único archivo PHP que descarga la última release desde GitHub, la extrae en el servidor y crea la base de datos SQLite, todo desde el navegador sin acceso SSH.

**Requisitos**

| Componente | Mínimo |
|---|---|
| PHP | 8.0+ |
| Extensiones PHP | `pdo_sqlite`, `sqlite3`, `fileinfo`, `xmlwriter`, `zip`, `gd`, `curl` |
| Servidor | Apache con `mod_rewrite` |
| Directorio de instalación | Escribible por el servidor web |

**Pasos**

1. Descarga `instalar.php` del repositorio [EasyPodcast-Installer](https://github.com/educollado/EasyPodcast-Installer) y súbelo al directorio raíz de tu servidor web (debe estar vacío o ser el único archivo).

2. Abre el instalador en tu navegador:
   ```
   https://tu-dominio.com/instalar.php
   ```

3. Sigue los tres pasos del asistente:
   - **Compatibilidad** — verifica que el servidor cumple todos los requisitos.
   - **Directorio** — comprueba si el directorio está limpio.
   - **Instalación** — descarga y extrae la última release de EasyPodcast y crea la base de datos.

4. Al finalizar serás redirigido al panel de administración (`/admin.php`).

> **Seguridad:** el instalador intenta borrarse a sí mismo al completarse. Si no pudo eliminarse, **borra `instalar.php` manualmente** antes de usar la aplicación.

---

## Instalación con Apache (Opción 2)

**1. Crear la base de datos**

```bash
sqlite3 podcast.sqlite < schema.sql
mkdir -p audios images cache
```

**2. Dar permisos de escritura al usuario del servidor web**

```bash
chown -R www-data:www-data podcast.sqlite feed.xml audios images cache favicon.ico
chmod 775 audios images cache
chmod 664 podcast.sqlite feed.xml favicon.ico
```

**3. Activar `mod_rewrite`** con `AllowOverride All` en el virtual host.

**4. Primer acceso**

Abre `/admin.php`, crea el administrador y configura el canal en `Gestión del Podcast`.

**5. Comprobaciones**

```
/          → Portada pública
/feed.php  → Feed RSS dinámico
/feed.xml  → Feed generado
/sitemap.xml
/robots.txt
```

---

## Instalación rápida con Docker (Opción 3)

```bash
# Descargar el compose y levantar
curl -O https://github.com/educollado/EasyPodcast/releases/latest/download/docker-compose.yml
docker compose up -d
```

Abre `http://localhost:8080/admin.php` y crea el primer administrador. La base de datos se inicializa automáticamente.

O con `docker run`:

```bash
docker run -d \
  --name easypodcast \
  -p 8080:80 \
  -v $(pwd)/data/db:/var/www/html/data \
  -v $(pwd)/data/audios:/var/www/html/audios \
  -v $(pwd)/data/images:/var/www/html/images \
  -v $(pwd)/data/cache:/var/www/html/cache \
  ghcr.io/educollado/easypodcast:latest
```

La imagen del proyecto se publica para `linux/amd64` y `linux/arm64` sobre una base PHP/Apache fijada a una versión concreta, no sobre una etiqueta flotante. Así las actualizaciones de seguridad del contenedor pasan a ser cambios explícitos y revisables.

---

## Funcionalidades

### Web pública

| Página | Descripción |
|---|---|
| Portada (`index.php`) | Episodios publicados con paginación, reproductor inline y buscador |
| Episodio (`episode.php`) | URL amigable `/YYYY/MM/slug`, reproductor, descripción HTML saneada y descarga |
| Búsqueda (`search.php`) | Búsqueda por título y descripción con paginación |

### Multipodcast

El modo Multipodcast se activa desde `multipodcast.php`. Conserva una sola base de datos y un único administrador, pero aísla episodios, páginas, redes, tokens y estadísticas mediante `podcast_id`. Cada podcast recibe un directorio único (`/mi-podcast/`), con portada, búsqueda, páginas, feed, sitemap y tracking propios.

La raíz `/` puede mostrar un resumen de todos los podcasts o la portada de uno destacado. El resumen permite configurar su propia imagen hero desde `multipodcast.php`. Cuando se destaca un podcast, `/feed.xml` y `/sitemap.xml` actúan como alias del podcast seleccionado, mientras que sus enlaces canónicos permanecen bajo `/mi-podcast/...`. Los medios se almacenan en `audios/<slug>/` e `images/<slug>/`.

### Feed RSS

- `feed.php` genera el RSS en tiempo real; `feed.xml` se regenera automáticamente tras cada cambio.
- `rss_item_limit`: número máximo de episodios en el feed (`0` = sin límite).
- `home_items_per_page`: episodios por página en portada y búsqueda (mínimo 1, por defecto 20).
- La publicación programada usa la hora local configurada en PHP y se activa en la siguiente petición web; los administradores pueden previsualizar episodios `scheduled` antes de su publicación.

### SEO

- Redirección canónica 301 según `podcast.link`.
- Etiquetas `canonical`, `meta description`, Open Graph y `link rel="alternate"` al RSS.
- `rel="prev"` / `rel="next"` en paginación; páginas 2+ marcadas como `noindex,follow`.
- Datos estructurados JSON-LD (`PodcastSeries` en portada, `PodcastEpisode` en episodio).
- `robots.txt` dinámico con URL del sitemap calculada desde `podcast.link`.
- `sitemap.xml` estático regenerado automáticamente.

### Seguridad

- Formularios de administración protegidos con CSRF.
- CSP con nonce por petición para scripts inline permitidos y bloqueo de atributos `on*`.
- Cookies de sesión con `HttpOnly`, `SameSite` y `Secure` cuando la petición usa HTTPS.
- Limitación de intentos de login y verificación TOTP.
- Tokens API almacenados como hash SHA-256 en vez de en claro.
- El actualizador web verifica mediante SHA-256 cada paquete descargado antes de extraerlo.
- Dependabot supervisa semanalmente la imagen Docker y las GitHub Actions.

#### Límites de importación

Para evitar agotamiento de memoria o disco al importar contenido externo:

| Recurso | Límite |
|---|---:|
| Feed RSS remoto | 10 MiB |
| Imagen remota | 25 MiB por archivo |
| Audio remoto | 128 MiB por archivo |
| ZIP de medios | 2.000 entradas |
| Archivo dentro de un ZIP | 128 MiB descomprimidos |
| Contenido total de un ZIP | 512 MiB descomprimidos |
| Ratio máximo de compresión ZIP | 200:1 |

Las imágenes y los audios importados se validan por su MIME real. Los ZIP solo pueden contener formatos multimedia admitidos dentro de `images/` o `audios/`.

### Panel de administración

| Página | Función |
|---|---|
| `admin.php` | Login/logout y acceso al panel |
| `multipodcast.php` | Activación, selección, creación y borrado seguro de podcasts |
| `podcast_management.php` | Metadatos del canal |
| `episodes_management.php` | CRUD de episodios con borrado seguro de imágenes compartidas con la portada del podcast |
| `add_episode.php` | Alta/edición con editor visual HTML (Jodit), grabación con preescucha Web Audio y subida de imágenes con orientación EXIF |
| `import_feed.php` | Importación de episodios desde feed RSS externo |
| `backups.php` | Exportar/importar base de datos y ficheros |
| `cache_management.php` | Habilitar/deshabilitar caché y regenerar imágenes |
| `twofa_management.php` | 2FA TOTP con códigos de recuperación |
| `social_management.php` | Gestión de enlaces a redes sociales |
| `change_password.php` | Cambio de contraseña |
| `stats.php` | Estadísticas de episodios, descargas/reproducciones y caché |
| `pages_management.php` / `add_page.php` | Páginas estáticas con jerarquía padre/hijo |
| `api_tokens.php` | Generación y revocación de tokens para la API REST |
| `api_docs.php` | Documentación interactiva de la API REST |
| `update.php` | Actualizaciones desde GitHub Releases con recordatorio de copia de seguridad |

---

## Requisitos

| Componente | Requisito |
|---|---|
| PHP | 8+ |
| Extensiones | `pdo_sqlite`, `sqlite3`, `fileinfo`, `xmlwriter`, `zip`, `gd`, `curl` |
| Servidor | Apache con `mod_rewrite` |
| Permisos de escritura | `podcast.sqlite`, `feed.xml`, `audios/`, `images/`, `cache/`, `favicon.ico` |


---

## Base de datos

| Tabla | Uso |
|---|---|
| `app_settings` | Activación Multipodcast y podcast destacado en portada |
| `podcast` | Metadatos y directorio único de cada canal |
| `episodes` | Episodios, podcast propietario y estado de publicación |
| `management` | Credenciales y configuración 2FA (TOTP) |
| `social` | Enlaces a redes sociales (una fila) |
| `pages` | Páginas estáticas con jerarquía padre/hijo |
| `api_tokens` | Tokens de API (reservada) |
| `estadisticas` | Datos brutos de descargas/reproducciones (7 días) |
| `estadisticas_mensuales` | Resumen mensual histórico |
| `estadisticas_anuales` | Resumen anual histórico |

### Migraciones de esquema

El sistema usa `PRAGMA user_version` de SQLite. `lib/migration_runner.php` comprueba la versión en cada request y aplica solo las migraciones pendientes. Las instalaciones nuevas parten de `user_version` actualizado en `schema.sql`.

#### Cómo añadir una migración

Edita `lib/migration_runner.php`:

```php
// 1. Bloque condicional en runMigrations()
if ($version < 22) {
    migration_v22($pdo);
    $pdo->exec('PRAGMA user_version = 22');
}

// 2. Función de migración
function migration_v22(PDO $pdo): void
{
    $pdo->exec('ALTER TABLE episodes ADD COLUMN nueva_columna TEXT');
}
```

Y actualiza `schema.sql` con `PRAGMA user_version = 22`.

#### Historial de versiones

| Versión | Cambios |
|---|---|
| 1 | Añade `rss_item_limit`, `home_items_per_page`, `write_audio_metadata`, `cache_enabled` a `podcast` |
| 2 | Crea tabla `api_tokens` |
| 3 | Hace `pub_date` nullable en `episodes` |
| 4 | Añade columnas TOTP a `management` |
| 5 | Crea tabla `social` |
| 6 | Crea tabla `pages` con índice `idx_pages_status` |
| 7 | Crea índice `idx_episodes_link` sobre `episodes(link)` |
| 8 | Añade `app_language` a `podcast` (idioma de la interfaz) |
| 9 | Añade `name` y `last_used_at` a `api_tokens` |
| 10 | Añade `short_description` a `episodes` |
| 11 | Renombra columna `description` → `content` en `episodes` |
| 12 | Añade `admin_theme` a `podcast` (tema visual del sitio) |
| 13 | Reparación idempotente: añade `admin_theme` si falta pese a `user_version = 12` |
| 14 | Añade tablas e índices de estadísticas agregadas |
| 15 | Añade `action_type` a `estadisticas` para diferenciar descargas y reproducciones |
| 16 | Migra `api_tokens` a hash + sufijo visible y añade alcance explícito |
| 17 | Añade `public_theme_mode_auto` a `podcast` para guardar el modo público `Según sistema` como ajuste global |
| 18 | Activa EasyPodcast como tema predeterminado sin alterar otros temas elegidos |
| 19 | Añade `hero_image_url` al podcast para configurar la imagen de cabecera |
| 20 | Guarda la fecha y versión de la comprobación diaria de actualizaciones del panel |
| 21 | Añade configuración Multipodcast y aísla contenido, tokens, medios y estadísticas por `podcast_id` |

---

## Estructura del proyecto

```
├── index.php                        # Portada pública
├── episode.php                      # Página de episodio
├── search.php                       # Búsqueda
├── page.php                         # Página estática
├── feed.php                         # Feed RSS dinámico
├── feed.xml                         # Feed RSS generado
├── feed_builder.php                 # Constructor de feed
├── robots.php                       # robots.txt dinámico
├── sitemap.xml                      # Sitemap estático
├── track.php                        # Seguimiento de descargas
├── admin.php                        # Login/logout admin
├── canonical_redirect.php           # Redirección canónica 301
├── .htaccess                        # Reglas Apache
├── schema.sql                       # Esquema de base de datos
├── podcast.sqlite                   # Base de datos SQLite
├── favicon.ico                      # Icono del sitio
├── 403.php                          # Error 403
├── 404.php                          # Error 404
├── header.php                       # Cabecera pública compartida
├── footer.php                       # Pie público compartido
├── admin_nav.php                    # Navegación del panel admin
├── add_episode.php                  # Formulario de episodio
├── add_page.php                     # Formulario de página estática
├── episodes_management.php          # Lista de episodios
├── pages_management.php             # Lista de páginas estáticas
├── podcast_management.php          # Configuración del podcast
├── social_management.php           # Gestión de redes sociales
├── backups.php                      # Exportar/importar datos
├── cache_management.php            # Configuración de caché
├── change_password.php              # Cambio de contraseña
├── import_feed.php                  # Importar desde feed externo
├── media_cleanup.php                # Limpieza de archivos huérfanos
├── stats.php                        # Estadísticas
├── twofa_management.php             # Configuración 2FA TOTP
├── update.php                       # Actualizaciones automáticas
├── upload_audio_ajax.php             # Subida de audio por AJAX
├── api_docs.php                     # Documentación API
├── api_tokens.php                   # Gestión de tokens API
├── api/                             # API REST
│   └── index.php
├── lib/
│   ├── migration_runner.php         # Sistema de migraciones BD
│   ├── episode_helpers.php          # Slugs, fechas, MIME, rutas
│   ├── episode_save_handler.php     # Validación y guardado de episodios
│   ├── episode_query.php            # Consultas de episodios
│   ├── episode_seo.php               # SEO de episodios
│   ├── upload_service.php           # Subida de audio/imagen e ID3
│   ├── id3_service.php              # Metadatos ID3 para MP3
│   ├── seo_helpers.php              # Canonical, URLs, meta description
│   ├── view_helpers.php             # esc(), HTML saneado, slugs e imágenes
│   ├── public_episode_helpers.php   # Rutas y slugs públicos
│   ├── page_helpers.php              # Utilidades para páginas estáticas
│   ├── page_save_handler.php        # Guardado de páginas
│   ├── cache_service.php            # Caché (lectura/escritura/limpieza)
│   ├── csrf.php                     # Protección CSRF
│   ├── csp.php                      # Content-Security-Policy y nonces
│   ├── session.php                  # Arranque seguro de sesión
│   ├── auth_security.php            # Throttling de autenticación
│   ├── admin_theme.php              # Temas visuales (carga y selección)
│   ├── totp.php                     # Lógica TOTP
│   ├── twofa_handler.php            # Gestor de 2FA
│   ├── api_helpers.php              # Autenticación y utilidades de API
│   ├── api_episode_handler.php      # Handler API de episodios
│   ├── api_misc_handlers.php        # Handlers API varios
│   ├── api_pages_handler.php        # Handler API de páginas
│   ├── api_podcast_handler.php      # Handler API del podcast
│   ├── api_social_handler.php       # Handler API de redes sociales
│   ├── api_system_handler.php       # Handler API del sistema
│   ├── api_tokens_handler.php       # Handler API de tokens
│   ├── import_feed_handler.php      # Parser de feed externo
│   ├── backup_handler.php           # Exportación/importación de datos
│   ├── cache_management_handler.php # Gestor de configuración de caché
│   ├── change_password_handler.php  # Gestor de cambio de contraseña
│   ├── download_handler.php         # Gestor de descargas
│   ├── media_cleanup_handler.php    # Limpieza de archivos
│   ├── podcast_management_handler.php # Gestor de configuración podcast
│   ├── scheduler.php                 # Programación de episodios
│   ├── search_query.php              # Consultas de búsqueda
│   ├── search_seo.php                # SEO de búsqueda
│   ├── social_handler.php           # Gestor de redes sociales
│   ├── stats_downloads_handler.php  # Gestor de estadísticas de descargas
│   ├── stats_handler.php            # Gestor de estadísticas
│   ├── update_handler.php            # Gestor de actualizaciones
│   ├── version.php                   # Versión de la aplicación
│   ├── i18n.php                      # Internacionalización
│   ├── home_query.php                # Consultas de portada
│   ├── home_seo.php                  # SEO de portada
│   ├── sitemap_builder.php           # Constructor de sitemap
│   ├── add_episode_query.php         # Consultas formulario episodio
│   └── admin_query.php               # Consultas del panel admin
├── assets/
│   ├── css/
│   │   ├── common.css                # Estilos base públicos
│   │   ├── index.css                 # Portada
│   │   ├── episode.css               # Página de episodio
│   │   ├── header.css                # Cabecera compartida
│   │   ├── themes.css                # Temas visuales (aplicados via data-theme)
│   │   ├── dark.css                  # Tema oscuro base
│   │   ├── admin-common.css          # Estilos base del panel admin
│   │   ├── admin.css                 # Login/panel
│   │   ├── podcast_management.css    # Gestión del podcast
│   │   ├── episodes_management.css   # Gestión de episodios
│   │   └── jodit.min.css             # Editor HTML Jodit
│   └── js/
│       ├── public.js                 # JS público
│       ├── admin.js                  # JS del panel admin
│       ├── add_episode.js            # JS formulario episodio
│       ├── add_page.js               # JS formulario página
│       ├── podcast_management.js     # JS gestión podcast
│       ├── import_feed.js             # JS importación feed
│       ├── media_cleanup.js          # JS limpieza archivos
│       ├── stats.js                  # JS estadísticas
│       ├── twofa_management.js       # JS 2FA
│       ├── theme-mode.js             # JS modo tema
│       ├── jodit.min.js              # Editor HTML Jodit
│       ├── lame.min.js               # Codificador MP3
│       └── qrcode.min.js             # Generador de QR
├── audios/                          # Audios subidos
├── images/                          # Imágenes subidas
└── cache/                           # Caché pública en runtime
```

---

## Temas visuales

El administrador elige el tema desde el panel (`admin.php` → tarjeta **Apariencia**). El slug se guarda en `podcast.admin_theme` y se aplica server-side mediante el atributo `data-theme` en `<html>`, sin JavaScript ni parpadeo. Desde esa misma tarjeta también puede activar `Según sistema` como ajuste global para las páginas públicas; ese estado se guarda en `podcast.public_theme_mode_auto` y se refleja en `data-theme-mode`.

| Slug | Nombre | Estilo |
|---|---|---|
| `easypodcast` | EasyPodcast | Predeterminado, azul marino y verde petróleo |
| `corporate` | Corporate | Claro editorial, acento rojo y estética de eduardocollado.com |
| `default` | Amber Parchment | Claro cálido, acento terracota |
| `oscuro` | Ember Noir | Oscuro cálido, acento naranja |
| `agua` | Arctic Tide | Claro azul |
| `fuego` | Crimson Dusk | Claro naranja |
| `invierno` | Frost Haven | Claro azul frío |
| `hacker` | Matrix Core | Oscuro, texto verde terminal |
| `monokai` | Monokai | Oscuro, paleta Monokai |
| `pink-essence` | Pink Essence | Claro rosa, acento magenta |
| `monocromo` | Silver Void | Escala de grises pura |

Los temas se definen en `assets/css/themes.css` mediante variables CSS con selectores `html[data-theme="slug"]`. Para añadir uno nuevo basta con agregar la entrada en `lib/admin_theme.php` (`ADMIN_THEMES`) y el bloque de variables en `themes.css`.

La cabecera pública admite una imagen hero opcional desde `podcast_management.php`. La imagen cubre la cabecera sin cambiar sus dimensiones, incorpora una superposición oscura y muestra el texto en blanco. Las imágenes subidas se recortan de forma centrada hasta un máximo de 1720 × 720 px y se comprimen con calidad 82 como WebP cuando GD lo soporta o como JPEG en caso contrario, sin dependencias adicionales. Si el campo queda vacío, se conserva la cabecera normal del tema seleccionado.

Al entrar en `admin.php`, EasyPodcast consulta como máximo una vez al día si existe una release nueva en GitHub. El resultado se comparte entre sesiones mediante la base de datos y, cuando hay una actualización, el panel muestra un aviso con acceso directo a `update.php`.

### Archivos CSS

| CSS | Uso |
|---|---|
| `common.css` | Estilos base públicos |
| `index.css` | Portada |
| `episode.css` | Página de episodio |
| `header.css` | Cabecera compartida |
| `themes.css` | Temas visuales (cargado el último en todas las páginas) |
| `admin-common.css` | Estilos base del panel admin |
| `admin.css` | Login/panel |
| `podcast_management.css` | Gestión del podcast |
| `episodes_management.css` | Gestión de episodios |
| `dark.css` | Tema oscuro base |
| `jodit.min.css` | Editor HTML Jodit |

---

## Modelo de URLs

| Recurso | URL |
|---|---|
| Portada | `/` |
| Episodio | `/YYYY/MM/slug` |
| Feed dinámico | `/feed.php` |
| Feed generado | `/feed.xml` |
| Sitemap | `/sitemap.xml` |
| Robots | `/robots.txt` |

Con Multipodcast activo, cada recurso queda bajo `/<podcast>/`: `/<podcast>/YYYY/MM/slug`, `/<podcast>/feed.xml`, `/<podcast>/sitemap.xml`, `/<podcast>/search` y `/<podcast>/api/v1/...`.

---

## Sitio de referencia

**[aratospodcast.com](https://www.aratospodcast.com)** — podcast personal del autor, desplegado con esta misma aplicación.

---

## Licencia

EasyPodcast es **Software Libre** distribuido bajo **GNU GPL v3 o posterior**.
Consulta [`LICENSE`](LICENSE) para los términos completos.
