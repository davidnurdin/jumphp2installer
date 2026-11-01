# jumphp2installer's server: FrankenPHP (PHP 8.4 + Caddy) running the Symfony app. Built from the repository's root
# (it needs installer/src and games/installer/ui too): docker compose -f server/compose.yaml up -d --build
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_mysql intl zip opcache \
 && printf "memory_limit=512M\nupload_max_filesize=50M\npost_max_size=60M\n" > "$PHP_INI_DIR/conf.d/jumphp2installer.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

COPY server/composer.json server/composer.lock server/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY server/ ./
RUN rm -rf lib/jumphp2installer public/installer-ui && mkdir -p lib/jumphp2installer public/installer-ui
COPY installer/src lib/jumphp2installer/src
COPY games/installer/ui/index.html games/installer/ui/style.css games/installer/ui/app.js public/installer-ui/

RUN composer dump-autoload --classmap-authoritative --no-dev \
 && APP_SECRET=build php bin/console importmap:install \
 && APP_SECRET=build php bin/console asset-map:compile \
 && rm -rf var/cache/* \
 && chmod +x docker/entrypoint.sh

VOLUME ["/app/var/packages", "/app/var/installers"]
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
