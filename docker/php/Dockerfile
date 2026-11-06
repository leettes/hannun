FROM php:8.4-fpm-alpine

# pdo_sqlite 는 기본 이미지에 포함되어 있다
RUN apk add --no-cache icu-dev libzip-dev sqlite \
    && docker-php-ext-install intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html
