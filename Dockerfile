# Use PHP CLI so we can run the built-in server
FROM php:8.2-cli

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy Composer files first for better layer caching
COPY composer.json ./
COPY composer.lock* ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Copy application code
# Note: config/ directory no longer exists - all configs moved to src/Core/Config/
COPY src/ src/
COPY public/ public/

# Expose port
EXPOSE 9000

# Refresh autoload after full source copy
RUN composer dump-autoload --optimize --no-interaction || true

# Start PHP built-in server
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-9000} -t public/"]
