#!/bin/sh
# Waits for the database, updates its tables, fetches the base installers the first time, then serves.
set -e
cd /app
mkdir -p var/packages var/installers var/cache/installers
if [ -z "${APP_SECRET:-}" ]; then
  [ -s var/packages/.app_secret ] || php -r 'echo bin2hex(random_bytes(16));' > var/packages/.app_secret
  APP_SECRET="$(cat var/packages/.app_secret)"
  export APP_SECRET
fi
for i in $(seq 1 60); do
  php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1 && break
  [ "$i" = 60 ] && { echo "the database does not answer" >&2; exit 1; }
  sleep 1
done
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration -q
php bin/console cache:warmup -q
if [ -z "$(ls -A var/installers 2>/dev/null)" ] && [ "${JUMPHP2INSTALLER_FETCH:-1}" = 1 ]; then
  php bin/console app:installers:fetch || echo "(no base installers yet: bin/console app:installers:fetch later)"
fi
chown -R www-data:www-data var 2>/dev/null || true
exec "$@"
