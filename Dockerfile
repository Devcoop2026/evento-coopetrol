# Imagen de producción del Evento Fin de Año Coopetrol (Laravel 13 + Livewire 4, PHP 8.4) para Render.
# Nginx + PHP-FPM en un solo contenedor (serversideup/php), sin usuario root y escuchando en el puerto 8080
# (en Render la variable PORT=8080 le indica a qué puerto enviar el tráfico). Ver deploy/render/DESPLIEGUE-RENDER.md.
FROM serversideup/php:8.4-fpm-nginx

USER root

# Extensiones: pdo_pgsql, zip y opcache ya vienen en la imagen; sodium es parte de PHP. Se agrega intl.
# Cliente de PostgreSQL 17 (repositorio oficial PGDG) para evento:respaldo: pg_dump debe ser de la misma versión
# mayor del servidor (Neon: 16 o 17) o superior.
RUN install-php-extensions intl \
    && apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && . /etc/os-release \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-17 \
    && apt-get purge -y gnupg \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# PHP: comprobantes de hasta 25 MB (el límite del evento es soporte_max_mb de data/config.json), sin revelar la versión.
# Las variables PHP_* también ajustan el client_max_body_size de Nginx en la imagen base.
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=25M \
    PHP_POST_MAX_SIZE=30M \
    PHP_MEMORY_LIMIT=256M \
    AUTORUN_ENABLED=false \
    LOG_CHANNEL=stderr \
    LOG_SEGURIDAD=php://stderr
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-evento.ini

# Arranque: cachés de Laravel, migraciones y recarga de data/tarifas.json (lo ejecuta el entrypoint de la imagen base).
COPY --chmod=755 deploy/docker/50-evento.sh /etc/entrypoint.d/50-evento.sh

WORKDIR /var/www/html
USER www-data

# Dependencias primero (capa reutilizable mientras composer.lock no cambie).
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY --chown=www-data:www-data . .
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction

EXPOSE 8080
