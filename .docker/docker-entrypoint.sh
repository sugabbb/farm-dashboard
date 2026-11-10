#!/bin/sh

set -eu

mkdir -p \
  storage/app/private \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ "${APP_ENV:-production}" = "production" ]; then
  php artisan optimize
else
  php artisan optimize:clear
fi

exec docker-php-entrypoint "$@"
