FROM php:8.2-fpm

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    libzip-dev \
    nginx \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        bcmath \
    && docker-php-ext-enable pdo_pgsql \
    && apt-get clean

# Verify extension is installed
RUN php -m | grep pdo_pgsql

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

COPY src/ src/
COPY public/ public/

# Create php.ini with extensions
RUN echo "extension=pdo_pgsql.so" > /usr/local/etc/php/conf.d/pdo_pgsql.ini

# Simple nginx config
RUN echo 'server { listen 9000; root /var/www/html/public; index index.php; location / { try_files $uri $uri/ /index.php?$args; } location ~ \.php$ { fastcgi_pass 127.0.0.1:9001; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; include fastcgi_params; } }' > /etc/nginx/sites-enabled/default

# Start both PHP-FPM and nginx
RUN mkdir -p /var/log/nginx
CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"

EXPOSE 9000
