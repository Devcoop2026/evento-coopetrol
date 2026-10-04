#!/bin/sh
# Arranque del contenedor (lo ejecuta el entrypoint de serversideup/php antes de iniciar Nginx y PHP-FPM).
# Si algo falla el contenedor no arranca y el error queda en los logs de Render.
set -e
cd /var/www/html

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migraciones pendientes (en Neon con la conexión del servicio; si fallan por el pooler, vea DESPLIEGUE-RENDER.md).
php artisan migrate --force

# Tarifas, cupos y eventos compartidos de data/tarifas.json y data/agencias_evento.json.
php artisan evento:tarifas --solo-recargar
