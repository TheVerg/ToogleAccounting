#!/usr/bin/env sh
set -eu

if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
