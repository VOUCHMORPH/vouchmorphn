# ==============================================================================
# docker/worker/Dockerfile
#
# The worker fleet. Deploy this as a SEPARATE Railway service from the
# web one above — scale throughput by increasing the replica count on
# this service, completely independent of the web service's own
# scaling. Workers coordinate purely through database row locking (see
# BatchExecutionQueueService::claimJobs), so there's no configuration
# needed to add or remove replicas — Railway (or whatever orchestrator)
# can start and stop them freely.
#
# No nginx, no php-fpm — this container's only job is running
# worker.php in a loop. Much smaller image, much simpler than the web
# service.
# ==============================================================================

FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
        libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# pcntl is required for worker.php's graceful-shutdown signal handling
# (SIGTERM/SIGINT) — without it, a Railway redeploy or scale-down would
# hard-kill a worker mid-job instead of letting it finish its current
# job first. Not built into the CLI image by default.
RUN docker-php-ext-install pdo pdo_pgsql pgsql pcntl

WORKDIR /var/www/html
COPY . /var/www/html

# WORKER_ID lets you tell replicas apart in logs if your orchestrator
# doesn't already inject a unique hostname/replica id you'd rather use
# instead — set this per-replica if you have that option, otherwise
# worker.php falls back to its own process id, which is unique per
# container but not stable across restarts.
ENV WORKER_CLAIM_SIZE=20
ENV WORKER_POLL_INTERVAL=2

CMD ["php", "worker.php"]
