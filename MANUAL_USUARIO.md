# Manual de usuario de EasyPodcast

EasyPodcast permite gestionar uno o varios podcasts desde el navegador y publica automáticamente una web, un feed RSS y un sitemap por cada podcast. Este manual explica el uso diario de la aplicación una vez instalada.

> Para instalar EasyPodcast o consultar sus requisitos técnicos, consulta el [README](README.md).

## Índice

1. [Conceptos básicos](#1-conceptos-básicos)
2. [Primer acceso](#2-primer-acceso)
3. [Configurar el podcast](#3-configurar-el-podcast)
4. [Publicar un capítulo](#4-publicar-un-capítulo)
5. [Gestionar capítulos](#5-gestionar-capítulos)
6. [Importar otro podcast](#6-importar-otro-podcast)
7. [Crear páginas y añadir redes sociales](#7-crear-páginas-y-añadir-redes-sociales)
8. [Consultar estadísticas](#8-consultar-estadísticas)
9. [Copias de seguridad](#9-copias-de-seguridad)
10. [Caché y mantenimiento](#10-caché-y-mantenimiento)
11. [Usuarios, contraseña y 2FA](#11-usuarios-contraseña-y-2fa)
12. [Gestionar varios podcasts](#12-gestionar-varios-podcasts)
13. [API y automatizaciones](#13-api-y-automatizaciones)
14. [Actualizar EasyPodcast](#14-actualizar-easypodcast)
15. [Qué publica EasyPodcast](#15-qué-publica-easypodcast)
16. [Rutina recomendada](#16-rutina-recomendada)
17. [Problemas frecuentes](#17-problemas-frecuentes)

## 1. Conceptos básicos

EasyPodcast tiene dos zonas:

- **Web pública:** muestra la portada, los capítulos publicados, las páginas informativas, el buscador y los enlaces sociales.
- **Panel de administración:** permite configurar el podcast, crear capítulos, consultar estadísticas y realizar tareas de mantenimiento.

En el panel se utiliza la palabra **capítulo** para referirse a cada episodio del podcast.

Cada capítulo puede estar en uno de estos estados:

- `draft`: borrador. No aparece en la web ni en el feed. Puede guardarse solo con un título y completarse más tarde.
- `scheduled`: programado. Se publicará cuando llegue la fecha y hora indicadas.
- `published`: publicado. Aparece en la web pública y en el feed RSS.

La publicación programada no necesita cron. Cuando llega la fecha, EasyPodcast publica los capítulos pendientes en la siguiente visita a la web o al panel. La hora utilizada es la hora local configurada en PHP.

## 2. Primer acceso

1. Abre `https://tu-dominio.com/admin.php`.
2. Si todavía no existe un administrador, introduce un nombre de usuario y una contraseña y repítela para confirmar.
3. Pulsa **Crear usuario y entrar**.
4. Entra en **Podcast** y completa los datos básicos del canal antes de publicar el primer capítulo.

En accesos posteriores puedes identificarte con el nombre de usuario o el correo electrónico. Usa **Salir** para cerrar la sesión, especialmente si utilizas un equipo compartido.

## 3. Configurar el podcast

Abre **Administración > Podcast**. Los datos de esta pantalla se usan tanto en la web como en el feed RSS.

### Datos principales

- **Título:** nombre público del podcast.
- **URL principal:** dirección pública de la instalación, por ejemplo `https://podcast.ejemplo.com`.
- **Descripción:** presentación general del programa.
- **Idioma:** idioma declarado en el feed RSS.
- **Idioma del panel:** idioma de la interfaz administrativa y de las páginas públicas.
- **Autor, propietario y email:** información editorial incluida en el feed.
- **Categorías:** se pueden seleccionar hasta tres categorías compatibles con Apple Podcasts.
- **Explícito:** indica si el contenido general del podcast es explícito.
- **Tipo iTunes:** define si el podcast es episódico o serial.
- **Copyright:** texto legal mostrado en el feed.

### Publicación y apariencia

- **Cantidad de elementos del Feed RSS:** limita los capítulos incluidos en el feed. El valor `0` significa sin límite.
- **Cantidad de elementos de la portada:** controla cuántos capítulos aparecen por página.
- **Escribir metadatos ID3:** añade al MP3 datos como título, artista, álbum, fecha, comentario y número de pista al subirlo.
- **Imagen del podcast:** portada cuadrada del canal. Puede indicarse mediante una URL o subirse desde el dispositivo.
- **Imagen del hero:** cabecera panorámica opcional. Las imágenes subidas se recortan y optimizan automáticamente.
- **Apariencia:** desde el panel principal puedes escoger el tema visual y decidir si seguirá la preferencia clara u oscura del sistema.

Pulsa **Guardar podcast**. Los cambios públicos vacían automáticamente la caché y regeneran el feed y el sitemap cuando corresponde.

## 4. Publicar un capítulo

Abre **Administración > Añadir**.

### Flujo recomendado

1. Escribe el **título**.
2. Mantén el estado `draft` mientras preparas el contenido.
3. Añade una **descripción breve** para la portada y el **contenido** completo para la página del capítulo y el feed.
4. Añade el audio mediante una URL, subiendo un archivo o grabándolo con el micrófono.
5. Revisa imagen, autor, contenido explícito, temporada, número y tipo de episodio.
6. Selecciona `published` para publicar ahora o `scheduled` para indicar una fecha futura.
7. Pulsa **Guardar capítulo**.
8. Comprueba el resultado con **Vista previa** y revisa también la portada pública.

### Campos importantes

- **GUID:** identificador único utilizado por los lectores RSS. Si se deja vacío, EasyPodcast lo genera.
- **URL del capítulo:** se genera automáticamente desde el título. También puedes usar **Generar URL** o proporcionar una URL válida.
- **Descripción (sólo texto):** extracto opcional de hasta 4.000 caracteres. Si existe, sustituye al contenido completo en la portada.
- **Contenido:** texto completo editado visualmente; admite hasta 10.000 caracteres, incluidas las etiquetas HTML.
- **Fecha de publicación:** si publicas y la dejas vacía se usa la fecha actual; en capítulos programados es obligatoria.
- **Audio:** al subir un archivo se completan su URL, MIME y tamaño. Si usas una URL externa, revisa manualmente esos datos.
- **Duración:** utiliza el formato `HH:MM:SS`. EasyPodcast intenta calcularla desde un audio local si no se ha proporcionado.
- **Explícito:** puede heredar la configuración general o definirse para ese capítulo.
- **Tipo:** `full` para un episodio normal, `trailer` para un avance y `bonus` para contenido extra.
- **Imagen y autor:** si se dejan vacíos, se utilizan los valores generales del podcast.

Para publicar o programar hacen falta el contenido y los datos válidos del audio. Un borrador solo necesita título.

### Grabar desde el navegador

1. Despliega **Grabar desde micrófono**.
2. Autoriza el acceso al micrófono.
3. Pulsa **Grabar** y, al terminar, **Parar**.
4. Usa **Escuchar grabación** para comprobarla.
5. Pulsa **Usar esta grabación** y espera la confirmación de que se ha subido.
6. Guarda el capítulo.

No cierres la página mientras se codifica o sube la grabación.

### Metadatos del MP3

Si activaste la escritura automática de ID3, EasyPodcast actualizará los metadatos al subir el audio. Al editar un capítulo ya existente puedes usar **Actualizar metadatos del MP3 actual** para volver a escribirlos.

## 5. Gestionar capítulos

Abre **Administración > Capítulos**. La pantalla separa los capítulos publicados, los borradores y los programados.

Desde aquí puedes:

- buscar por título o contenido;
- editar un capítulo;
- abrir una vista previa, incluso si todavía es borrador o está programado;
- borrar un capítulo.

Al borrar, se elimina el registro de la base de datos. Su audio y su imagen solo se borran del servidor si ningún otro capítulo ni la portada del podcast los utiliza. Aun así, conviene crear una copia de seguridad antes de una limpieza importante.

Al modificar un capítulo publicado, EasyPodcast actualiza el feed, el sitemap y la caché pública.

## 6. Importar otro podcast

La opción **Importar feed RSS** copia a EasyPodcast capítulos de un feed externo y descarga localmente sus audios e imágenes.

1. Abre **Administración > Importar feed RSS**.
2. Pega la URL completa del feed y pulsa **Vista previa**.
3. Compara los metadatos actuales con los del feed y marca solo los campos que quieras sobrescribir.
4. Selecciona los capítulos que quieras importar.
5. Deja desmarcada la importación de GUID duplicados salvo que necesites duplicarlos expresamente.
6. Pulsa **Iniciar importación** y no cierres la página hasta que termine.
7. Revisa los capítulos importados, la portada y el feed.

La URL principal del podcast no se sustituye por la del feed importado. Importaciones grandes pueden tardar varios minutos y dependen de los límites de subida, memoria y tiempo de ejecución del servidor.

## 7. Crear páginas y añadir redes sociales

### Páginas estáticas

En **Administración > Páginas** puedes crear contenido como “Acerca de”, “Contacto” o “Aviso legal”.

1. Pulsa **Añadir página**.
2. Escribe título y contenido.
3. Revisa el **slug**, formado solo por letras minúsculas, números y guiones.
4. Elige `draft` o `published`.
5. Opcionalmente selecciona una **página padre** para crear una jerarquía de dos niveles.
6. Usa **Orden** para controlar la posición; los números menores aparecen antes.
7. Guarda y comprueba la vista previa.

### Redes sociales

En **Administración > Redes Sociales** puedes añadir las URL completas de blog, LinkedIn, Mastodon, X, Pixelfed, Instagram, YouTube, GitHub y Bluesky. Solo las redes configuradas aparecen como iconos en la cabecera pública.

## 8. Consultar estadísticas

Abre **Administración > Estadísticas** para consultar:

- capítulos publicados, borradores y total almacenado;
- tamaño total de los audios según la base de datos;
- último capítulo publicado;
- estado, número de páginas y tamaño de la caché;
- descargas y reproducciones.

Las estadísticas de actividad se organizan en:

- **Diario:** eventos recientes de hasta siete días, con fecha, capítulo, tipo e IP.
- **Mensual:** acumulados por mes, con filtro por año.
- **Anual:** acumulados por año.
- **Resumen:** total de descargas y reproducciones por capítulo.

Puedes ordenar las tablas pulsando sus cabeceras. Las peticiones de comprobación `HEAD` de los lectores no cuentan como descargas.

## 9. Copias de seguridad

Abre **Administración > Backups**. EasyPodcast separa la copia de la base de datos de la copia de los archivos multimedia.

### Crear una copia completa

1. Pulsa **Exportar base de datos** y guarda el archivo SQLite.
2. Descarga todas las partes ofrecidas en **Exportar imágenes**.
3. Descarga todas las partes ofrecidas en **Exportar audios**.
4. Si se indica que un archivo supera el límite del ZIP, descárgalo manualmente mediante el enlace mostrado.
5. Guarda todos los archivos juntos, con la fecha y la versión de EasyPodcast.

Los ZIP se dividen en partes de hasta 127 MB. Las imágenes generadas se excluyen porque pueden regenerarse.

### Restaurar

- **Importar base de datos** restaura el contenido y la configuración desde un SQLite válido. Haz antes una copia del estado actual: la restauración sustituye la base de datos en uso.
- **Importar ficheros** admite uno o varios ZIP y audios sueltos. Sube todas las partes necesarias para recuperar los medios.

Después de restaurar, revisa la portada, varios capítulos, el feed y las imágenes. Si alguna miniatura falta, usa **Caché > Regenerar imágenes**.

## 10. Caché y mantenimiento

### Caché

La caché pública guarda el HTML de la portada, los capítulos, el feed y el sitemap para responder más rápido.

- **Habilitar caché pública:** recomendado en producción.
- **Borrar caché web:** elimina únicamente las respuestas HTML cacheadas; se generarán de nuevo al recibir visitas.
- **Regenerar imágenes:** vuelve a crear las variantes de 80, 144 y 220 píxeles de las imágenes del podcast y los capítulos.

Los cambios públicos normales ya invalidan la caché automáticamente. Bórrala manualmente si observas contenido antiguo después de una restauración o de una incidencia.

### Archivos huérfanos

En instalaciones Multipodcast, el administrador global dispone de **Limpiar**, que busca audios e imágenes no utilizados por ningún capítulo. Revisa la selección antes de pulsar **Borrar seleccionados**: la eliminación no se puede deshacer.

## 11. Usuarios, contraseña y 2FA

### Cambiar la contraseña

Abre **Contraseña**, introduce la contraseña actual y la nueva siguiendo las indicaciones de la pantalla. No compartas cuentas entre varias personas si utilizas Multipodcast; crea usuarios independientes.

### Activar la autenticación en dos pasos

1. Abre **2FA** y pulsa **Activar 2FA**.
2. Escanea el código QR con una aplicación TOTP, como Google Authenticator, Aegis o similar. También puedes introducir la clave manualmente.
3. Escribe el código de seis dígitos para confirmar.
4. Guarda inmediatamente los ocho códigos de recuperación en un lugar seguro.

Cada código de recuperación se usa una sola vez. Puedes regenerarlos o desactivar 2FA confirmando la operación con tu contraseña. En el acceso es posible recordar un dispositivo durante siete días.

### Restringir el acceso a `admin.php` por IP

El administrador global puede abrir **Multipodcast > Seguridad** para permitir el acceso a `admin.php` únicamente desde determinadas direcciones IPv4, IPv6 o rangos CIDR. El bloqueo está deshabilitado por defecto y debe habilitarse expresamente en dos pasos. Antes de confirmar, incluye siempre la IP desde la que administras EasyPodcast: una configuración incorrecta puede dejarte sin acceso al panel.

La activación es voluntaria y queda bajo la responsabilidad del usuario. Después de pulsar **Habilitar bloqueo**, revisa el aviso rojo y pulsa **Estoy seguro** únicamente si has comprobado la lista.

#### Recuperar el acceso si te has bloqueado por error

Si ya no puedes abrir `admin.php`, accede a los archivos del servidor mediante SSH, SFTP o el administrador de archivos de tu alojamiento. Abre el archivo `.htaccess` situado en el directorio principal de EasyPodcast y elimina **únicamente el bloque completo**, desde la línea `# BEGIN` hasta la línea `# END`, ambas incluidas:

```apache
# BEGIN EasyPodcast: bloqueo por IP de admin.php
# Bloqueo al admin.php
<Files "admin.php">
    Order Deny,Allow
    Deny from all
    Allow from x.x.x.x
</Files>
# END EasyPodcast: bloqueo por IP de admin.php
```

Guarda `.htaccess` sin modificar las demás reglas y vuelve a abrir `admin.php`. Al retirar ese bloque, la restricción por IP queda deshabilitada. Si había varias líneas `Allow from`, debes eliminar también todas ellas como parte del mismo bloque.

### Usuarios de podcast

En modo Multipodcast, el administrador global puede abrir **Usuarios** para crear cuentas y asignarles uno o varios podcasts. Un usuario limitado solo ve y administra los podcasts asignados; no puede acceder a las herramientas globales.

Se puede desactivar temporalmente una cuenta, cambiar sus podcasts o asignarle una contraseña nueva. Al eliminarla también se eliminan sus tokens API.

## 12. Gestionar varios podcasts

Multipodcast permite alojar varios canales en una sola instalación, manteniendo separados sus capítulos, páginas, redes sociales, estadísticas y tokens.

### Activarlo

1. Abre **Multipodcast > Configuración Multipodcast**.
2. Marca **Activar Multipodcast**.
3. Elige el directorio del podcast actual, por ejemplo `mi-podcast`. Solo admite minúsculas, números y guiones.
4. Decide qué mostrará la raíz del sitio:
   - un resumen de todos los podcasts; o
   - la portada de un único podcast seleccionado.
5. Si usas el resumen, configura su idioma, tema, título, subtítulo e imagen de cabecera.
6. Guarda la configuración.

Los archivos multimedia conservan sus URL al activar Multipodcast.

### Crear y administrar podcasts

1. Abre **Podcasts**.
2. Escribe el título y un directorio libre.
3. Pulsa **Crear podcast**.
4. Usa **Administrar podcast** para completar su configuración y publicar contenido.

El selector de la barra superior cambia entre el panel global y los podcasts disponibles. Cada podcast tiene rutas propias bajo `https://tu-dominio.com/directorio/`.

Puedes marcar un podcast como principal. Este será el que permanezca si desactivas Multipodcast.

### Operaciones que cambian URL o eliminan contenido

- **Cambiar directorio** modifica las URL públicas del podcast, sus páginas, feed y capítulos. Hazlo solo si puedes actualizar los enlaces externos.
- **Borrar podcast** crea primero una copia ZIP y después elimina el podcast, sus capítulos, estadísticas y medios exclusivos. Es necesario escribir su título para confirmar. Descarga y conserva el ZIP ofrecido.
- **Desactivar Multipodcast** conserva el podcast principal, crea copias de los secundarios y después los elimina. La pantalla exige una confirmación explícita y el título exacto del principal.

## 13. API y automatizaciones

La sección **API** permite integrar EasyPodcast con scripts, n8n u otras aplicaciones.

1. Abre **API Tokens**.
2. Indica un nombre reconocible y, si quieres, una fecha de expiración.
3. Elige el alcance:
   - `content` para automatizaciones habituales de contenidos;
   - `admin` solo para operaciones administrativas sensibles.
4. Genera el token y cópialo en ese momento: no volverá a mostrarse completo.
5. Consulta **Documentación de la API** para ver endpoints y ejemplos.

Revoca inmediatamente un token que ya no se use o que pueda haberse expuesto. Los usuarios limitados solo pueden operar sobre sus podcasts asignados.

## 14. Actualizar EasyPodcast

1. Crea al menos una copia de seguridad de la base de datos y, preferiblemente, también de los medios.
2. Abre **Actualizar**.
3. Compara la versión instalada con la última disponible.
4. Si hay una nueva versión, pulsa **Actualizar a v…** y espera a que finalice.
5. Lee las novedades mostradas y comprueba la portada, el panel, un capítulo, el feed y el sitemap.

El actualizador descarga la versión desde GitHub Releases, verifica su integridad y sustituye los archivos de la aplicación. No modifica la base de datos, los audios ni las imágenes directamente; las migraciones necesarias se aplican automáticamente al cargar la aplicación.

## 15. Qué publica EasyPodcast

En una instalación con un solo podcast, las rutas habituales son:

| Dirección | Contenido |
|---|---|
| `/` | Portada pública y buscador |
| `/AAAA/MM/slug` | Página pública de un capítulo |
| `/feed.php` | Feed RSS generado en tiempo real |
| `/feed.xml` | Copia estática del feed regenerada al guardar cambios |
| `/sitemap.xml` | Sitemap para buscadores |
| `/robots.txt` | Reglas para rastreadores y enlace al sitemap |
| `/admin.php` | Acceso al panel de administración |

En modo Multipodcast, cada canal utiliza su directorio, por ejemplo `/mi-podcast/`, `/mi-podcast/feed.xml` y `/mi-podcast/sitemap.xml`. La raíz puede mostrar el resumen global o el podcast destacado.

Para enviar el podcast a una plataforma de distribución, proporciona la URL pública de `feed.xml` correspondiente al canal. Después de publicar un capítulo, comprueba que aparece tanto en la web como en ese feed.

## 16. Rutina recomendada

### Antes de publicar

- Revisa título, descripción breve, contenido, audio e imagen.
- Escucha el principio y el final del archivo.
- Comprueba si el episodio es explícito y su numeración.
- Usa `draft` hasta que todo esté listo.

### Después de publicar

- Abre la portada y la página del capítulo.
- Reproduce o descarga el audio.
- Comprueba `feed.xml`.
- Revisa título, imagen y descripción al compartir la URL.

### Mantenimiento periódico

- Exporta la base de datos con frecuencia y antes de cada actualización.
- Conserva copias periódicas de audios e imágenes fuera del servidor.
- Revisa las estadísticas y el espacio ocupado.
- Revoca tokens y desactiva usuarios que ya no necesiten acceso.
- Comprueba actualizaciones y aplica primero una copia de seguridad.

## 17. Problemas frecuentes

### Un capítulo no aparece públicamente

- Comprueba que su estado sea `published`.
- Si está programado, revisa la fecha, la hora y la zona horaria del servidor.
- Abre la web para que se procese la publicación programada.
- Borra la caché web si el problema continúa.

### El audio no se reproduce

- Comprueba que la URL del audio sea accesible.
- Revisa el MIME, el tamaño y el formato del archivo.
- Si se subió desde el panel, confirma que la operación terminó antes de guardar.

### El feed no refleja un cambio

- Guarda de nuevo el podcast o el capítulo.
- Compara `feed.php` con `feed.xml`.
- Borra la caché web.
- Verifica que el servidor tenga permisos de escritura sobre `feed.xml` y la carpeta `cache/`.

### Falta una imagen o miniatura

- Comprueba la URL original.
- Abre **Caché > Regenerar imágenes**.
- Verifica que el servidor pueda escribir en `images/` e `images/generated/`.

### No se puede importar un feed

- Comprueba que la URL sea pública y devuelva RSS válido.
- Verifica que PHP tenga habilitada la extensión cURL.
- Prueba con menos capítulos si el servidor tiene límites estrictos.
- No cierres la pestaña durante la descarga.

### No se puede guardar o subir contenido

Normalmente se debe a límites de PHP o permisos del servidor. El administrador del alojamiento debe revisar el tamaño máximo de subida, el tiempo de ejecución y los permisos de escritura de `podcast.sqlite`, `audios/`, `images/`, `cache/`, `feed.xml` y `favicon.ico`.
