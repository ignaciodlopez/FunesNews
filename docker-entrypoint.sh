#!/bin/sh
set -e

# Asegurar estructura y permisos básicos sobre el volumen montado de datos.
mkdir -p /var/www/html/data/img_cache
chown -R www-data:www-data /var/www/html/data || true
chmod -R 775 /var/www/html/data || true
rm -f /var/www/html/data/aggregator.lock || true

# Arrancar el servicio cron en background para que el aggregator
# se ejecute cada 2 minutos independientemente de las visitas al sitio.
service cron start

# Arrancar Apache en foreground (necesario para que Docker lo mantenga vivo)
exec apache2-foreground
