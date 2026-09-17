# syntax=docker/dockerfile:1

# ─── Stage 1: production dependencies ─────────────────────────────────────────
# Composer lives only here. The runtime image never gets it, which keeps it
# smaller and gives it one less thing that can execute arbitrary code.
FROM composer:2 AS vendor

WORKDIR /app

# Copied first, on their own, so a change to application code does not make
# Composer resolve the dependency graph again.
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --no-interaction \
      --prefer-dist

# Now the source, and the classmap that needs it.
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# ─── Stage 2: dependencies including dev ──────────────────────────────────────
# Only used by the test image.
FROM composer:2 AS vendor-dev

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-interaction

# ─── Stage 3: runtime ─────────────────────────────────────────────────────────
FROM dunglas/frankenphp:php8.4-alpine AS production

# pdo_pgsql is the application database. pdo_sqlite is what the test suite runs
# on, and shipping it is what lets the suite run inside this very image.
# pcntl is required by Octane, sockets by Reverb.
RUN install-php-extensions \
      pdo_pgsql \
      pdo_sqlite \
      pcntl \
      sockets \
      opcache \
      intl \
      zip \
      bcmath

WORKDIR /app

COPY --from=vendor /app /app
RUN chmod -R ug+rw storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8000 8080

ENTRYPOINT ["entrypoint"]
CMD ["app"]

# ─── Stage 4: test ────────────────────────────────────────────────────────────
# The suite runs in an image identical to production, rather than in whatever
# the developer's machine happens to have installed.
FROM production AS test

COPY --from=vendor-dev /app/vendor /app/vendor

# Swapping vendor/ is not enough: the package manifest was cached during the
# no-dev install, so Laravel never discovers Collision and `artisan test` does
# not exist. The manifest has to be rebuilt against the dev dependencies.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && php artisan package:discover --ansi

CMD ["test"]
