# EasyPodcast

[![Versión](https://img.shields.io/badge/versión-1.8.0-blue)](https://github.com/educollado/EasyPodcast/releases/latest)
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
| Extensiones PHP | `pdo_sqlite`, `sqlite3`, `fileinfo`, `xmlwriter`, `zip`, `gd` |
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

---

## Novedades 1.8.0

- Publicación programada de episodios sin cron: nuevo estado `scheduled` con fecha y hora de publicación futura. El episodio se publica automáticamente en la siguiente petición web.
- Nueva sección "Capítulos Programados" en la gestión de episodios.

---

## Funcionalidades

### Web pública

| Página | Descripción |
|---|---|
| Portada (`index.php`) | Episodios publicados con paginación, reproductor inline y buscador |
| Episodio (`episode.php`) | URL amigable `/YYYY/MM/slug`, reproductor, descripción Markdown y descarga |
| Búsqueda (`search.php`) | Búsqueda por título y descripción con paginación |

### Feed RSS

- `feed.php` genera el RSS en tiempo real; `feed.xml` se regenera automáticamente tras cada cambio.
- `rss_item_limit`: número máximo de episodios en el feed (`0` = sin límite).
- `home_items_per_page`: episodios por página en portada y búsqueda (mínimo 1, por defecto 20).

### SEO

- Redirección canónica 301 según `podcast.link`.
- Etiquetas `canonical`, `meta description`, Open Graph y `link rel="alternate"` al RSS.
- `rel="prev"` / `rel="next"` en paginación; páginas 2+ marcadas como `noindex,follow`.
- Datos estructurados JSON-LD (`PodcastSeries` en portada, `PodcastEpisode` en episodio).
- `robots.txt` dinámico con URL del sitemap calculada desde `podcast.link`.
- `sitemap.xml` estático regenerado automáticamente.

### Panel de administración

| Página | Función |
|---|---|
| `admin.php` | Login/logout y acceso al panel |
| `podcast_management.php` | Metadatos del canal |
| `episodes_management.php` | CRUD de episodios |
| `add_episode.php` | Alta/edición con editor Markdown y subida de audio/imagen |
| `import_feed.php` | Importación de episodios desde feed RSS externo |
| `backups.php` | Exportar/importar base de datos y ficheros |
| `cache_management.php` | Habilitar/deshabilitar caché y regenerar imágenes |
| `twofa_management.php` | 2FA TOTP con códigos de recuperación |
| `social_management.php` | Gestión de enlaces a redes sociales |
| `change_password.php` | Cambio de contraseña |
| `stats.php` | Estadísticas de episodios y caché |
| `pages_management.php` / `add_page.php` | Páginas estáticas con jerarquía padre/hijo |
| `update.php` | Actualizaciones desde GitHub Releases |

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
| `podcast` | Metadatos del canal (una fila) |
| `episodes` | Episodios y estado de publicación |
| `management` | Credenciales y configuración 2FA (TOTP) |
| `social` | Enlaces a redes sociales (una fila) |
| `pages` | Páginas estáticas con jerarquía padre/hijo |
| `api_tokens` | Tokens de API (reservada) |

### Migraciones de esquema

El sistema usa `PRAGMA user_version` de SQLite. `lib/migration_runner.php` comprueba la versión en cada request y aplica solo las migraciones pendientes. Las instalaciones nuevas parten de `user_version` actualizado en `schema.sql`.

#### Cómo añadir una migración

Edita `lib/migration_runner.php`:

```php
// 1. Bloque condicional en runMigrations()
if ($version < 14) {
    migration_v14($pdo);
    $pdo->exec('PRAGMA user_version = 14');
}

// 2. Función de migración
function migration_v14(PDO $pdo): void
{
    $pdo->exec('ALTER TABLE episodes ADD COLUMN nueva_columna TEXT');
}
```

Y actualiza `schema.sql` con `PRAGMA user_version = 14`.

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

---

## Estructura del proyecto

```
├── index.php                        # Portada pública
├── episode.php                      # Página de episodio
├── search.php                       # Búsqueda
├── feed.php / feed.xml              # RSS dinámico y generado
├── robots.php / sitemap.xml         # SEO
├── admin.php                        # Panel de administración
├── canonical_redirect.php           # Redirección canónica 301
├── schema.sql / podcast.sqlite      # Esquema y base de datos
├── lib/
│   ├── migration_runner.php         # Sistema de migraciones BD
│   ├── episode_helpers.php          # Slugs, fechas, MIME, rutas
│   ├── episode_save_handler.php     # Validación y guardado de episodios
│   ├── upload_service.php           # Subida de audio/imagen e ID3
│   ├── id3_service.php              # Metadatos ID3 para MP3
│   ├── seo_helpers.php              # Canonical, URLs, meta description
│   ├── view_helpers.php             # esc(), slugify(), Markdown, imágenes
│   ├── public_episode_helpers.php   # Rutas y slugs públicos
│   ├── cache_service.php            # Caché (lectura/escritura/limpieza)
│   ├── csrf.php                     # Protección CSRF
│   ├── admin_theme.php              # Temas visuales (carga y selección)
│   ├── import_feed_handler.php      # Parser de feed externo
│   └── backup_handler.php           # Exportación/importación de datos
├── assets/css/                      # Hojas de estilo por página
│   ├── themes.css                   # Temas visuales (aplicados via data-theme)
├── audios/                          # Audios subidos
├── images/                          # Imágenes subidas
└── cache/                           # Caché pública en runtime
```

---

## Temas visuales

El administrador elige el tema desde el panel (`admin.php` → tarjeta **Apariencia**). El slug se guarda en `podcast.admin_theme` y se aplica server-side mediante el atributo `data-theme` en `<html>`, sin JavaScript ni parpadeo.

| Slug | Nombre | Estilo |
|---|---|---|
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

---

## Sitio de referencia

**[aratospodcast.com](https://www.aratospodcast.com)** — podcast personal del autor, desplegado con esta misma aplicación.

---

## Licencia

EasyPodcast es **Software Libre** distribuido bajo **GNU GPL v3 o posterior**.
Consulta [`LICENSE`](LICENSE) para los términos completos.
