# TWENTY ONE Esports for Anthropic's OSS Scanner (https://red.anthropic.com/oss-scanner/).
#
# /src   the repository checkout, with Composer and npm dependencies installed and the Vite assets built
#
# The app runs on SQLite (database/database.sqlite, migrated and seeded); production uses PostgreSQL + Redis, the test
# suite SQLite in memory. Start the app with `oss-scanner-serve` (http://127.0.0.1:8000, logs in /tmp/serve.log).
# The default test suites (Unit, Feature, Nostr) run offline: `php artisan test --compact [path]`.
FROM php:8.5-cli-trixie

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

# pdo_sqlite ships with the base image; GD draws the share cards and stream slides; ffmpeg decodes the audio clips the
# caster API accepts.
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates git unzip curl procps sqlite3 ffmpeg libgmp-dev libzip-dev libpq-dev \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd gmp pcntl sockets zip bcmath pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Node only for the Vite build and the Stacker run verifier (resources/js/stacker/verify.mjs).
COPY --from=node:26-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:26-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN { echo 'memory_limit=2G'; echo 'display_errors=Off'; echo 'log_errors=On'; } > /usr/local/etc/php/conf.d/oss-scanner.ini

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

RUN composer install --no-progress \
    && npm ci --no-audit --no-fund \
    && npm run build

# A local app on SQLite with the demo seeders (a test user, clans, chess games, a series match).
RUN cp .env.example .env \
    && php artisan key:generate --ansi \
    && touch database/database.sqlite \
    && php artisan migrate --force --seed

RUN printf '%s\n' '#!/bin/sh' 'cd /src' \
      'pgrep -f "artisan serve" >/dev/null || (php artisan serve --host=127.0.0.1 --port=8000 >/tmp/serve.log 2>&1 &)' \
      'until curl -fsS -o /dev/null http://127.0.0.1:8000/up 2>/dev/null; do sleep 0.5; done; echo "serving http://127.0.0.1:8000"' \
      > /usr/local/bin/oss-scanner-serve \
    && chmod +x /usr/local/bin/oss-scanner-serve

# Smoke-test the app and the cheap tests without letting a failure abort the build.
RUN oss-scanner-serve && curl -fsS -o /dev/null http://127.0.0.1:8000/ \
    && (php artisan test --compact tests/Unit || echo "WARNING: unit tests reported failures")

# Keep build products out of the checkout's `git status`.
RUN printf '.env\ndatabase/database.sqlite\npublic/build/\n' >> /src/.git/info/exclude || true
