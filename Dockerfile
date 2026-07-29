FROM php:8.4-fpm

# ============================================================
# Install system packages
# ============================================================

RUN apt-get update && apt-get install -y \
    nginx \
    git \
    curl \
    unzip \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    chromium \
    fonts-liberation \
    libgbm1 \
    libnss3 \
    libx11-6 \
    libxcomposite1 \
    libxdamage1 \
    libxrandr2 \
    libgtk-3-0 \
    libasound2 \
    xdg-utils \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ============================================================
# Configure PHP
# ============================================================

RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

RUN { \
    echo "memory_limit=512M"; \
    echo "upload_max_filesize=100M"; \
    echo "post_max_size=100M"; \
    echo "max_execution_time=300"; \
    echo "opcache.enable=1"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.memory_consumption=128"; \
} > /usr/local/etc/php/conf.d/custom.ini

# ============================================================
# Set Chrome path for PDF generation
# ============================================================

ENV CHROME_PATH=/usr/bin/chromium

# ============================================================
# Verify installations
# ============================================================

RUN php -m | grep pgsql || (echo "ERROR: pgsql extension not installed" && exit 1)
RUN php -m | grep pdo_pgsql || (echo "ERROR: pdo_pgsql extension not installed" && exit 1)
RUN test -f /usr/bin/chromium || (echo "ERROR: chromium not found" && exit 1)

# ============================================================
# Composer
# ============================================================

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ============================================================
# Working directory
# ============================================================

WORKDIR /var/www/html

# ============================================================
# Composer install (DO NOT RUN composer update)
# ============================================================

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction

# ============================================================
# Copy application files
# ============================================================

COPY src/ src/
COPY public/ public/
COPY docker/nginx.conf /etc/nginx/sites-enabled/default

# ============================================================
# Optimize autoloader
# ============================================================

RUN composer dump-autoload --optimize --no-interaction

# ============================================================
# Expose port
# ============================================================

EXPOSE 9000

# ============================================================
# Start PHP-FPM and Nginx
# ============================================================

CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
