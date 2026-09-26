FROM php:8.4-fpm-bookworm

ARG FORWEXT_BUILD_VERSION=dev

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git libcurl4-openssl-dev libicu-dev libonig-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" curl intl mbstring opcache pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
    --optimize-autoloader --classmap-authoritative

COPY . /app
COPY deploy/advanced/php/forwext.ini /usr/local/etc/php/conf.d/zz-forwext.ini

RUN mkdir -p storage/cache storage/logs storage/locks storage/rate-limit storage/sessions storage/secrets \
    public/storage \
    && chown -R www-data:www-data storage config public/storage \
    && find storage config public/storage -type d -exec chmod 0750 {} \; \
    && find storage config public/storage -type f -exec chmod 0640 {} \;

ENV FORWEXT_BUILD_VERSION="${FORWEXT_BUILD_VERSION}"

EXPOSE 9000
USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'

CMD ["php-fpm", "-F"]
