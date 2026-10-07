#!/bin/sh
# Container start: update the database, run the background worker, serve the app.
set -e

# Short diagnosis in the deploy log (no secrets): PHP version, database driver, database host.
echo "[start] $(php -r 'echo "PHP ", PHP_VERSION, ", pdo_pgsql: ", extension_loaded("pdo_pgsql") ? "yes" : "MISSING";')"
php -r '
    $url = getenv("DATABASE_URL");
    if (false === $url || "" === $url) {
        fwrite(STDERR, "[start] ERROR: DATABASE_URL is not set. On Railway set it on the app service to \${{<your Postgres service>.DATABASE_URL}}.\n");
        exit(1);
    }
    if (str_contains($url, "\${{")) {
        fwrite(STDERR, "[start] ERROR: DATABASE_URL is the unresolved text \"$url\": the reference does not match a service. Pick it from the suggestions after typing \${{ in Railway Variables.\n");
        exit(1);
    }
    $u = parse_url($url);
    if (!isset($u["scheme"], $u["host"])) {
        fwrite(STDERR, "[start] ERROR: DATABASE_URL is set but is not a database URL (expected postgresql://user:password@host:port/name).\n");
        exit(1);
    }
    echo "[start] database: ", $u["scheme"], "://", $u["host"], ":", $u["port"] ?? "-", $u["path"] ?? "", "\n";
'

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
