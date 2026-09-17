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

# The server, not the application's database: the test role reaches Postgres
# before the database it is about to create exists.
wait_for_postgres_server() {
  echo "→ waiting for postgres at ${DB_HOST}:${DB_PORT:-5432}…"
  until php -r "new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432};dbname=postgres', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
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
    # The suite runs on SQLite by default, declared in phpunit.xml, and needs no
    # service. Point DB_CONNECTION at pgsql to run the same suite against the
    # engine production uses: that is the only way to see what SQLite cannot,
    # such as how jsonb normalises a value or what timestamptz does to an offset.
    if [ "${DB_CONNECTION}" = "pgsql" ]; then
      wait_for_postgres_server
      # RefreshDatabase migrates from empty on every test, but it cannot create
      # the database itself. Created here, so the suite never depends on the
      # state of a volume that may predate it.
      php -r "
        \$pdo = new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432};dbname=postgres', '${DB_USERNAME}', '${DB_PASSWORD}');
        \$exists = \$pdo->query(\"SELECT 1 FROM pg_database WHERE datname = '${DB_DATABASE}'\")->fetchColumn();
        if (! \$exists) { \$pdo->exec('CREATE DATABASE \"${DB_DATABASE}\"'); }
      "
    fi

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
