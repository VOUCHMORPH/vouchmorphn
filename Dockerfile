FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    nginx \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        bcmath \
    && docker-php-ext-enable pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN php -m | grep -q pdo_pgsql || (echo "ERROR: pdo_pgsql extension not installed" && exit 1)
RUN php -m | grep -q pgsql || (echo "ERROR: pgsql extension not installed" && exit 1)

RUN echo "extension=pdo_pgsql.so" > /usr/local/etc/php/conf.d/20-pdo_pgsql.ini \
    && echo "extension=pgsql.so" > /usr/local/etc/php/conf.d/20-pgsql.ini

RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# -------------------------------
# Composer install (FIXED: update phpspreadsheet specifically, then install)
# -------------------------------
COPY composer.json composer.lock ./
# First, update phpspreadsheet to fix the lock file mismatch
RUN composer update phpoffice/phpspreadsheet --no-dev --optimize-autoloader --no-interaction --prefer-dist
# Then run a regular install to ensure everything is consistent
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# -------------------------------
# App code
# -------------------------------
COPY src/ src/
COPY public/ public/
COPY docker/nginx.conf /etc/nginx/sites-enabled/default

# -------------------------------
# Autoload optimization (FIXED)
# -------------------------------
RUN composer dump-autoload --optimize --no-interaction

EXPOSE 9000

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
