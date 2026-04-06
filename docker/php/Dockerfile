FROM php:8.1-fpm

# системные зависимости
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install \
        pdo_pgsql \
        intl \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

# PHP-зависимости
RUN composer install --no-interaction --optimize-autoloader --no-dev --prefer-dist \
    && composer clear-cache

# Права
RUN chown -R www-data:www-data /app/runtime /app/web/assets \
    && chmod -R 755 /app/runtime /app/web/assets

USER www-data

EXPOSE 9000
CMD ["php-fpm"]