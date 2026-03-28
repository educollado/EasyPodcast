#!/bin/sh
set -e

# Asegurar que los directorios de datos tienen propietario correcto
# (necesario cuando el bind mount monta un directorio vacío del host)
for dir in /var/www/html/audios /var/www/html/images /var/www/html/cache /var/www/html/data; do
    mkdir -p "$dir"
    chown www-data:www-data "$dir"
done

# Inicializar BD si no existe (primera instalación)
DB_PATH="${PODCAST_DB_PATH:-/var/www/html/data/podcast.sqlite}"
if [ ! -f "$DB_PATH" ]; then
    php /var/www/html/docker/init_db.php "$DB_PATH"
    chown www-data:www-data "$DB_PATH"
fi

# Opción para desactivar la redirección HTTPS en desarrollo local.
# Crea un fichero de señal que .htaccess comprueba antes de redirigir,
# en lugar de modificar .htaccess (que el updater podría sobreescribir).
if [ "${DISABLE_HTTPS_REDIRECT:-false}" = "true" ]; then
    touch /var/www/html/docker/.disable_https_redirect
    echo "[entrypoint] Redirección HTTPS desactivada."
fi

exec apache2-foreground
