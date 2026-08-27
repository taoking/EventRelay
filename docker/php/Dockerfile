FROM composer:2 AS composer

FROM php:8.5-cli-alpine

RUN apk add --no-cache libzip-dev oniguruma-dev \
    && docker-php-ext-install bcmath mbstring pcntl pdo_mysql zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

EXPOSE 8000
