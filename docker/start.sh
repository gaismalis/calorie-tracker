#!/bin/sh
# Container start: update the database, run the background worker, serve the app.
set -e

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Background worker (AI estimate retries, emails). Restarts itself every hour or after 256 MB,
# and after a crash; the loop keeps it going for as long as the container runs.
(
    while true; do
        php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-interaction || true
        sleep 2
    done
) &

exec frankenphp run --config /etc/caddy/Caddyfile
