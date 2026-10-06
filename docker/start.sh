#!/bin/sh
# Container start: update the database, run the background worker, serve the app.
set -e

# Short diagnosis in the deploy log (no secrets): PHP version, database driver, database host.
echo "[start] $(php -r 'echo "PHP ", PHP_VERSION, ", pdo_pgsql: ", extension_loaded("pdo_pgsql") ? "yes" : "MISSING";')"
echo "[start] database: $(php -r '$u = parse_url(getenv("DATABASE_URL") ?: ""); echo ($u["scheme"] ?? "-"), "://", ($u["host"] ?? "NOT SET"), ":", ($u["port"] ?? "-"), ($u["path"] ?? "");')"

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
