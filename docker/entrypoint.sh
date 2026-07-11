#!/usr/bin/env sh
set -eu

if [ "${APP_ENV:-production}" = "production" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is required in production." >&2
        exit 1
    fi

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec gosu www-data "$@"
