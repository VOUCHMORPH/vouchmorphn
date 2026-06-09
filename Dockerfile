FROM php:8.2-fpm

# =========================
# System dependencies
# =========================
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    libzip-dev \
    nginx \
    && rm -rf /var/lib/apt/lists/*

# =========================
# PHP extensions
# =========================
RUN docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        zip \
        bcmath

# Verify extension exists at build time
RUN php -m | grep pdo_pgsql

# =========================
# Composer
# =========================
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# =========================
# Work directory
# =========================
WORKDIR /var/www/html

# =========================
# Copy app files
# =========================
COPY composer.json composer.lock* ./

RUN composer install --no-dev --optimize-autoloader --no-interaction || true

COPY src/ src/
COPY public/ public/

# =========================
# CRITICAL FIX: allow env vars in PHP-FPM (Railway fix)
# =========================
RUN sed -i 's/;clear_env = yes/clear_env = no/' /usr/local/etc/php-fpm.d/www.conf || true \
 && echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

# =========================
# Nginx config
# =========================
RUN echo 'server {
    listen 9000;
    root /var/www/html/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9001;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}' > /etc/nginx/sites-enabled/default

# =========================
# Logs
# =========================
RUN mkdir -p /var/log/nginx

# =========================
# Start both services
# =========================
CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"

EXPOSE 9000
