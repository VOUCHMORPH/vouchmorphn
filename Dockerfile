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
# composer.lock was regenerated (see commit 7f103bd, "Regenerate
# composer.lock for phpspreadsheet, dompdf, google2fa") to include
# phpoffice/phpspreadsheet, dompdf/dompdf, and pragmarx/google2fa, which
# were previously in composer.json but missing from the lock file. Back
# to `install` now for reproducible, pinned builds -- this will fail
# loudly (exit code 4) if composer.json and composer.lock ever drift
# out of sync again, which is the correct behavior for a production build.
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
COPY src/ src/
COPY public/ public/
COPY docker/nginx.conf /etc/nginx/sites-enabled/default
RUN composer dump-autoload --optimize --no-interaction
EXPOSE 9000
CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
