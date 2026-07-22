#!/bin/bash
set -e

PORT=${PORT:-8080}

# ── Reemplazar puerto en nginx.conf ──
sed -i "s/listen 8080;/listen $PORT;/g" /etc/nginx/nginx.conf

# ── Storage persistente (GCS montado en /mnt/media) ──
GCS_MOUNT="/mnt/media"

if [ -d "$GCS_MOUNT" ]; then
    MEDIA_DIR="$GCS_MOUNT"
else
    MEDIA_DIR="/tmp/storage/app/public"
    mkdir -p "$MEDIA_DIR"
fi

mkdir -p /tmp/storage/framework/{cache,sessions,testing,views}
mkdir -p /tmp/storage/logs
mkdir -p /tmp/bootstrap/cache

for dir in storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache; do
    rm -rf "/var/www/html/$dir"
    ln -sf "/tmp/$dir" "/var/www/html/$dir"
done

rm -rf /var/www/html/storage/app/public
mkdir -p /var/www/html/storage/app
ln -sf "$MEDIA_DIR" "/var/www/html/storage/app/public"

chown -R www-data:www-data /tmp/storage /tmp/bootstrap "$MEDIA_DIR" 2>/dev/null || true
chmod -R 775 /tmp/storage /tmp/bootstrap 2>/dev/null || true

ln -sf "$MEDIA_DIR" /var/www/html/public/storage 2>/dev/null || true

# ── Laravel optimizaciones ──
php artisan package:discover 2>/dev/null || true
php artisan optimize 2>/dev/null || true

# ── Iniciar servicios ──
php-fpm -D 2>/dev/null || true

exec nginx -g 'daemon off;'
