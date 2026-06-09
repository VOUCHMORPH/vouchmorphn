FROM php:8.2-fpm

# Install required dependencies and PostgreSQL extensions only
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    nginx \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Verify extension is installed (build will fail if not)
RUN php -m | grep -q pdo_pgsql || (echo "pdo_pgsql extension missing" && exit 1)

# Copy Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Copy application code
COPY src/ src/
COPY public/ public/

# Dump autoloader
RUN composer dump-autoload --optimize --no-interaction || true

# ===== CRITICAL: Fix for Railway environment variables =====
ENV PHP_FPM_CLEAR_ENV=no
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

# Simple nginx config
RUN echo 'server { listen 9000; root /var/www/html/public; index index.php; location / { try_files $uri $uri/ /index.php?$args; } location ~ \.php$ { fastcgi_pass 127.0.0.1:9001; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; include fastcgi_params; } }' > /etc/nginx/sites-enabled/default

EXPOSE 9000

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
