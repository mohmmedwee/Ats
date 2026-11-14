# syntax=docker/dockerfile:1.7
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-libs libpq libzip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql pcntl zip bcmath opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-gulfcart.ini
WORKDIR /var/www/html

# --- Development: source is bind-mounted; dependencies installed on start ---
FROM base AS dev
COPY docker/entrypoint-dev.sh /usr/local/bin/entrypoint-dev
RUN chmod +x /usr/local/bin/entrypoint-dev
ENTRYPOINT ["entrypoint-dev"]
CMD ["php-fpm"]

# --- Production: immutable image, no dev dependencies, non-root ---
FROM base AS prod
ENV APP_ENV=production APP_DEBUG=false
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]
