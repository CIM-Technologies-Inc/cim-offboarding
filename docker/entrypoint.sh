#!/bin/sh
set -e

cd /var/www/html

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

php artisan storage:link --quiet || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    runuser -u www-data -- php artisan migrate --force
fi

# Apache drops to www-data by itself; run everything else (queue worker,
# scheduler, artisan commands) as www-data so files it creates in storage
# stay writable by the web server.
if [ "$1" != "apache2-foreground" ] && [ "$(id -u)" = "0" ]; then
    exec runuser -u www-data -- "$@"
fi

exec "$@"