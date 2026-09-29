#!/bin/sh
set -e

cd /var/www/html

php artisan storage:link --quiet || true

# Without an APP_KEY in .env, generate one once and keep it in the storage
# volume; config/app.php falls back to this file.
if [ -z "${APP_KEY:-}" ] && [ ! -s storage/app/.app_key ]; then
    mkdir -p storage/app
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > storage/app/.app_key
    echo "Generated APP_KEY in storage/app/.app_key"
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    runuser -u www-data -- php artisan migrate --force

    # Seed the default admin only while no admin exists, so restarts never
    # reset an existing admin's password.
    if [ "${SEED_ADMIN:-false}" = "true" ] && ! runuser -u www-data -- php docker/has-admin.php; then
        runuser -u www-data -- php artisan db:seed --class=AdminUserSeeder --force
    fi
fi

# Apache drops to www-data by itself; run everything else (queue worker,
# scheduler, artisan commands) as www-data so files it creates in storage
# stay writable by the web server.
if [ "$1" != "apache2-foreground" ] && [ "$(id -u)" = "0" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
fi

exec "$@"