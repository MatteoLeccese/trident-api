#!/bin/sh
# One image, many roles.
#
# The role arrives as the container's command, so the API, the WebSocket server,
# the queue worker and the scheduler are all the same build — which is what makes
# "it works in one container but not the other" impossible.
set -e

ROLE="${1:-app}"

wait_for_postgres() {
  [ -z "${DB_HOST}" ] && return 0

  echo "→ waiting for postgres at ${DB_HOST}:${DB_PORT:-5432}…"
  until php -r "new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
  done
  echo "→ postgres is up"
}

# Caching config at build time would bake in build-time env; it is done here so
# the same image picks up whatever this environment provides.
php artisan config:clear >/dev/null 2>&1 || true

case "${ROLE}" in
  app)
    wait_for_postgres
    # --isolated takes a lock, so several replicas cannot migrate at once.
    php artisan migrate --force --isolated
    echo "→ serving the API on :8000"
    exec php artisan octane:frankenphp --host=0.0.0.0 --port=8000
    ;;
  reverb)
    wait_for_postgres
    echo "→ serving websockets on :8080"
    exec php artisan reverb:start --host=0.0.0.0 --port=8080
    ;;
  queue)
    wait_for_postgres
    exec php artisan queue:work --tries=3 --max-time=3600
    ;;
  scheduler)
    wait_for_postgres
    exec php artisan schedule:work
    ;;
  test)
    # PHPUnit directly, not `artisan test`: Collision's pretty printer expects a
    # terminal and files that are not in this image, and adds noise that looks
    # like failures. Raw PHPUnit gives clean output and an honest exit code.
    shift
    exec vendor/bin/phpunit "$@"
    ;;
  cli)
    shift
    exec "$@"
    ;;
  *)
    echo "unknown role: ${ROLE} (expected app, reverb, queue, scheduler, test or cli)" >&2
    exit 1
    ;;
esac
