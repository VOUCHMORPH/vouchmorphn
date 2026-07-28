# ==============================================================================
# docker/web/Dockerfile
#
# Replaces `php -S 0.0.0.0:8080` (PHP's single-threaded built-in
# development server — explicitly not meant for real traffic per PHP's
# own documentation) with a real production stack: Nginx in front,
# PHP-FPM behind it as a proper process pool, both supervised so the
# container restarts either one if it dies instead of the whole
# container going dark.
#
# This is the WEB service only. Workers (worker.php) and the stuck-job
# cron (stuck_job_recovery.php) run as SEPARATE services/deployments —
# see docker/worker/Dockerfile — since they have nothing to do with
# serving HTTP traffic and shouldn't share this container's lifecycle.
# ==============================================================================

FROM php:8.2-fpm

# ------------------------------------------------------------------------------
# System packages: nginx (the actual web server), supervisor (process
# manager so nginx + php-fpm both run and both get restarted on crash),
# and the Postgres client headers needed to build the pdo_pgsql
# extension below.
# ------------------------------------------------------------------------------
RUN apt-get update && apt-get install -y \
        nginx \
        supervisor \
        libpq-dev \
        libzip-dev \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

# PDO Postgres + a couple of commonly-needed extensions this codebase
# already relies on (zip for any export features, opcache for real
# performance in production — NOT enabled by default on the base image).
RUN docker-php-ext-install pdo pdo_pgsql pgsql zip opcache

# ------------------------------------------------------------------------------
# Opcache — this alone is a meaningful performance jump over the dev
# server, which recompiles every PHP file on every single request.
# validate_timestamps=0 means opcache never re-checks whether a file
# changed on disk — correct for a real deploy (each deploy is a fresh
# container), but means a hot-patched file inside a running container
# won't take effect until it restarts. That's the right tradeoff here;
# don't disable it for convenience during incident response — restart
# the container instead of editing a file in place.
# ------------------------------------------------------------------------------
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

WORKDIR /var/www/html
COPY . /var/www/html

# ------------------------------------------------------------------------------
# PHP-FPM pool tuning. The dev server has no concept of a process pool
# at all — every request just blocks the single thread. These defaults
# are a reasonable starting point for a small-to-medium instance; the
# real numbers should come from load testing (Track C item), not left
# as guesses. pm=dynamic scales worker processes between min/max based
# on load rather than a fixed count, which fits a workload that's quiet
# most of the time but needs to handle traffic when an Owner triggers a
# batch review/enqueue.
# ------------------------------------------------------------------------------
COPY docker/web/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/web/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/web/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Nginx's default site config conflicts with our own — remove it so
# there's exactly one active server block, not two competing on the
# same port.
RUN rm -f /etc/nginx/sites-enabled/default.orig 2>/dev/null || true

RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
