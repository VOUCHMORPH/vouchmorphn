# ==============================================================================
# docker/cron/Dockerfile
#
# Runs stuck_job_recovery.php on a fixed interval. If your platform has
# a native scheduled-job feature (Railway has a "Cron Job" service
# type, for example), prefer that over this — it's simpler and you get
# real scheduling semantics (skip-if-still-running, proper failure
# alerts) instead of a bare sleep loop. This Dockerfile exists as a
# portable fallback that works anywhere, including local testing.
# ==============================================================================

FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
        libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_pgsql pgsql

WORKDIR /var/www/html
COPY . /var/www/html

ENV STUCK_JOB_THRESHOLD_SECONDS=600
ENV CRON_INTERVAL_SECONDS=180

# Simple loop: run the recovery script, sleep, repeat. Logs to stdout
# so your platform's log aggregation picks it up like any other
# service — no separate log file to go find.
CMD ["sh", "-c", "while true; do php stuck_job_recovery.php; sleep ${CRON_INTERVAL_SECONDS}; done"]
