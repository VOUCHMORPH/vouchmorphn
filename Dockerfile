FROM php:8.2-fpm

# Install system dependencies
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

# VERIFY extension is installed (build will fail if not)
RUN php -m | grep -q pdo_pgsql || (echo "ERROR: pdo_pgsql extension not installed" && exit 1)
RUN php -m | grep -q pgsql || (echo "ERROR: pgsql extension not installed" && exit 1)

# Create php.ini with extensions explicitly enabled
RUN echo "extension=pdo_pgsql.so" > /usr/local/etc/php/conf.d/20-pdo_pgsql.ini \
    && echo "extension=pgsql.so" > /usr/local/etc/php/conf.d/20-pgsql.ini

# Allow PHP-FPM to see environment variables (CRITICAL for Railway)
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Copy application
COPY src/ src/
COPY public/ public/

# Dump autoloader
RUN composer dump-autoload --optimize --no-interaction || true

# Nginx configuration
RUN echo 'server { 
    listen 9000; 
    root /var/www/html/public; 
    index index.php; 
    location / { 
        try_files $uri $uri/ /index.php?$args; 
    } 
    location ~ \.php$ { 
        fastcgi_pass 127.0.0.1:9001; 
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; 
        include fastcgi_params; 
    } 
}' > /etc/nginx/sites-enabled/default

EXPOSE 9000

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
