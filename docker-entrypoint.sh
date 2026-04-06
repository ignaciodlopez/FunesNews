#!/bin/bash
set -e

# Arrancar el servicio cron en background para que el aggregator
# se ejecute cada 2 minutos independientemente de las visitas al sitio.
service cron start

# Arrancar Apache en foreground (necesario para que Docker lo mantenga vivo)
exec apache2-foreground
