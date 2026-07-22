# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: PHP dependencies (Composer)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2: Front-end assets (Vite / Tailwind)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --ignore-scripts
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: Runtime (PHP-FPM)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

# System packages + PHP extensions commonly needed by Laravel
RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Composer binary (for artisan scripts / optional runtime use)
COPY --from=vendor /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini

# Application source
COPY . .

# Bring in installed dependencies and built assets from earlier stages
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Finalise autoloader and fix permissions
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

# Entrypoint prepares the app (caches, migrations, storage link) at boot
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
