# syntax=docker/dockerfile:1.7

############################
# PHP base (FPM)
############################
FROM php:8.3-fpm-alpine AS php_base

COPY --from=ghcr.io/mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl opcache pdo_pgsql zip apcu \
    && apk add --no-cache fcgi tini

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-ariane.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-ariane.conf

WORKDIR /srv/app
ENV APP_ENV=prod APP_DEBUG=0

############################
# Build: dependencies and assets
############################
FROM php_base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && APP_SECRET=build php bin/console tailwind:build --minify \
    && APP_SECRET=build php bin/console asset-map:compile \
    && APP_SECRET=build php bin/console cache:warmup \
    && rm -rf var/tailwind tests

############################
# Production application image (php-fpm, worker)
############################
FROM php_base AS app

COPY --from=build --chown=www-data:www-data /srv/app /srv/app
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var

USER www-data
HEALTHCHECK --interval=30s --timeout=5s CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm"]

############################
# Caddy with the CrowdSec bouncer, serving the compiled public/ directory
############################
FROM caddy:2.10-builder AS caddy_build
RUN xcaddy build --with github.com/hslatman/caddy-crowdsec-bouncer/http

FROM caddy:2.10 AS caddy
COPY --from=caddy_build /usr/bin/caddy /usr/bin/caddy
COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY --from=build /srv/app/public /srv/app/public
