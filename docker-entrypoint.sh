#!/bin/sh
set -e

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:..." ]; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan migrate --force

exec "$@"