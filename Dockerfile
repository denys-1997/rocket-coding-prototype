# syntax=docker/dockerfile:1.7

# -----------------------------------------------------------------------------
# Stage 1 — Composer dependencies
# -----------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-progress \
    --no-interaction

# -----------------------------------------------------------------------------
# Stage 2 — Runtime (PHP-FPM)
# -----------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache \
        git \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        mysql-client \
        redis \
        supervisor \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 9000

CMD ["php-fpm"]
