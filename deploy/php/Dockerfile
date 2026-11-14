FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install -j1 \
       pdo_pgsql mbstring bcmath intl zip pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
