
FROM php:8.1-cli-alpine

RUN apk --no-cache update && apk --no-cache add \
        oniguruma-dev autoconf libmemcached-dev zlib-dev build-base $PHPIZE_DEP \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql mysqli mbstring \
    && pecl install --configureoptions 'with-libmemcached-dir="no" with-zlib-dir="no" with-system-fastlz="no" enable-memcached-igbinary="no" enable-memcached-msgpack="no" enable-memcached-json="no" enable-memcached-protocol="no" enable-memcached-sasl="no" enable-memcached-session="no"' memcached \
    && docker-php-ext-enable memcached \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-source delete

RUN rm -rf /var/cache/

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer