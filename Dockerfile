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
# Application image
############################################
# Pinned to a patch release so a rebuild can never reuse an older cached image. 8.3.36 (21 Oct 2026) fixes the
# critical remote-code-execution flaws CVE-2026-109618 and CVE-2026-109619; raise it with each PHP security release.
FROM serversideup/php:8.3.36-fpm-nginx AS app

USER root

RUN install-php-extensions intl gd bcmath exif

# Defence in depth for CVE-2026-109619: COLISAP never uses session upload progress, so it is switched off
# before any request is parsed (php.net advisory, 21 Oct 2026).
RUN printf 'session.upload_progress.enabled = 0\n' > /usr/local/etc/php/conf.d/zz-colisap-security.ini

# Queue worker and scheduler run as supervised s6 services alongside nginx + php-fpm.
# The services live in s6-rc.d; they are switched on from user-bundles.d, next to the image's own
# nginx and php-fpm entries. A "user" folder inside s6-rc.d would stop the container from starting.
COPY --chmod=755 docker/s6-overlay/ /etc/s6-overlay/

# Container defaults; any of these can be overridden from Dokploy > Environment.
#  - memory / time limits match the local setup the masterlist import was tested with
#    (PHP_MAX_EXECUTION_TIME is also nginx's wait time for PHP)
#  - "dynamic" keeps PHP workers warm so the first click after a quiet period is not slow
#  - the health check hits Laravel's own /up route, so a broken release is not put live
#  - AUTORUN runs migrations, storage:link and "artisan optimize" (config, routes, views,
#    Filament components and icons) on every container start
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_FPM_PM_CONTROL=dynamic \
    PHP_FPM_PM_MAX_CHILDREN=10 \
    HEALTHCHECK_PATH=/up \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true \
    SSL_MODE=off

WORKDIR /var/www/html

COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan filament:upgrade \
    && chown -R www-data:www-data storage bootstrap/cache public

USER www-data

EXPOSE 8080
