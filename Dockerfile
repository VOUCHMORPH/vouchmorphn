FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libyaml-dev \
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

RUN pecl install yaml && docker-php-ext-enable yaml
RUN php -m | grep -q yaml || (echo "ERROR: yaml extension not installed" && exit 1)

RUN echo "extension=pdo_pgsql.so" > /usr/local/etc/php/conf.d/20-pdo_pgsql.ini \
    && echo "extension=pgsql.so" > /usr/local/etc/php/conf.d/20-pgsql.ini

RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Fix: Update all missing packages and add google2fa in one pass
RUN composer require pragmarx/google2fa --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && composer update phpoffice/phpspreadsheet dompdf/dompdf --with-all-dependencies --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Copy application code
COPY src/ src/
COPY public/ public/
COPY docker/nginx.conf /etc/nginx/sites-enabled/default

# Dump autoloader
RUN composer dump-autoload --optimize --no-interaction

EXPOSE 9000

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
