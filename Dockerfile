FROM php:8.5.9-apache-trixie

# Imagen base fijada a una versión concreta para evitar deriva silenciosa entre builds.
# Las actualizaciones de seguridad deben entrar mediante una subida explícita de esta versión.

# Activar módulos Apache necesarios para .htaccess y cabeceras
RUN a2enmod rewrite headers setenvif

# Instalar dependencias del sistema y extensiones PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libsqlite3-dev \
        pkg-config \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo pdo_sqlite gd

# Límites de subida para audios (hasta 128 MiB)
COPY docker/php-uploads.ini $PHP_INI_DIR/conf.d/uploads.ini

# Configuración del VirtualHost
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Script de arranque
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Código de la aplicación
COPY --chown=www-data:www-data . /var/www/html/

# Pre-crear directorios de datos (serán sobreescritos por los volúmenes)
RUN mkdir -p \
        /var/www/html/audios \
        /var/www/html/images \
        /var/www/html/cache \
        /var/www/html/data \
    && chown -R www-data:www-data \
        /var/www/html/audios \
        /var/www/html/images \
        /var/www/html/cache \
        /var/www/html/data

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
