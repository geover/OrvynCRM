# syntax=docker/dockerfile:1
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache nginx supervisor tini icu-libs libzip libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql intl zip opcache pcntl gd \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build
WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
RUN chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi
ENTRYPOINT ["/sbin/tini", "--"]
# Role is chosen by the command in docker-compose.yml (web | worker | scheduler)
CMD ["php-fpm"]
