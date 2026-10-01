# syntax=docker/dockerfile:1

############################################
# Composer dependencies
############################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

############################################
# Frontend assets
############################################
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json .npmrc ./
RUN npm install

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

############################################
# Application image
############################################
FROM serversideup/php:8.3-fpm-nginx AS app

USER root

RUN install-php-extensions intl gd bcmath exif

# Queue worker and scheduler run as supervised s6 services alongside nginx + php-fpm
COPY --chmod=755 docker/s6-overlay/s6-rc.d/ /etc/s6-overlay/s6-rc.d/

ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true \
    SSL_MODE=off

WORKDIR /var/www/html

COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan filament:upgrade \
    && chown -R www-data:www-data storage bootstrap/cache public

USER www-data

EXPOSE 8080
