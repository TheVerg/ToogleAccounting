FROM node:22-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
RUN npm run build

FROM php:8.3-fpm-bookworm AS php-base

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        gosu \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd opcache pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/production.ini /usr/local/etc/php/conf.d/production.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM php-base AS app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

EXPOSE 80

FROM debian:bookworm-slim AS backup

RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client gzip tar \
    && rm -rf /var/lib/apt/lists/*

COPY docker/backup.sh /usr/local/bin/backup
RUN chmod +x /usr/local/bin/backup
ENTRYPOINT ["/usr/local/bin/backup"]
