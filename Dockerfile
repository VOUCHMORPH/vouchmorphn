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
RUN echo "display_errors = Off" >> /usr/local/etc/php/conf.d/99-production.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/99-production.ini \
    && echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/99-production.ini
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
# NOTE: using `composer update` (not `install`) here because composer.lock
# in this repo is currently out of sync with composer.json (missing
# phpoffice/phpspreadsheet, dompdf/dompdf, and now pragmarx/google2fa).
# `install` would refuse to run against a mismatched lock file.
# `update` resolves everything fresh against composer.json instead.
#
# TODO: once this deploy is confirmed working, run `composer update` in a
# real dev/CI environment with network access to packagist.org, commit the
# regenerated composer.lock, and switch this back to
# `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist`
# for reproducible, pinned builds.
RUN composer update --no-dev --optimize-autoloader --no-interaction --prefer-dist
COPY src/ src/
COPY public/ public/
COPY docker/nginx.conf /etc/nginx/sites-enabled/default
RUN composer dump-autoload --optimize --no-interaction
EXPOSE 9000
CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
