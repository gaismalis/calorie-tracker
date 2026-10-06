# Production image: FrankenPHP (PHP 8.4 + Caddy) serving the Symfony app, plus the Messenger worker.
# Used by Railway (or any Docker host). Local development doesn't use it; see `make start`.
FROM dunglas/frankenphp:1-php8.4 AS app

RUN install-php-extensions pdo_pgsql intl opcache zip apcu

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY docker/php.ini $PHP_INI_DIR/app.conf.d/10-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first (cached between builds unless composer files change)
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console importmap:install \
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup \
    && chmod -R a+rwX var

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start

CMD ["start"]
