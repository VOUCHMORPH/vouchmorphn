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
    # ============================================================
    # Chromium for PDF generation — FIXED package name
    # In Debian Trixie, it's just "chromium"
    # ============================================================
    chromium \
    libgbm-dev \
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

# ============================================================
# Verify Chromium installation
# ============================================================
RUN which chromium || (echo "ERROR: chromium not found" && exit 1)

# ============================================================
# Set Chrome path for PDF generation
# ============================================================
ENV CHROME_PATH=/usr/bin/chromium

RUN php -m | grep -q pdo_pgsql || (echo "ERROR: pdo_pgsql extension not installed" && exit 1)
RUN php -m | grep -q pgsql || (echo "ERROR: pgsql extension not installed" && exit 1)

RUN echo "extension=pdo_pgsql.so" > /usr/local/etc/php/conf.d/20-pdo_pgsql.ini \
    && echo "extension=pgsql.so" > /usr/local/etc/php/conf.d/20-pgsql.ini

RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# -------------------------------
# Composer install
# -------------------------------
COPY composer.json composer.lock ./
RUN composer update phpoffice/phpspreadsheet --no-dev --optimize-autoloader --no-interaction --prefer-dist
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# -------------------------------
# App code
# -------------------------------
COPY src/ src/
COPY public/ public/
COPY docker/nginx.conf /etc/nginx/sites-enabled/default

# -------------------------------
# Autoload optimization
# -------------------------------
RUN composer dump-autoload --optimize --no-interaction

EXPOSE 9000

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
