#!/usr/bin/env bash
set -euo pipefail

# Ensure runtime directories exist and are writable
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true

# Ensure a .env file exists (seeded from the example). Environment variables
# set by Docker still take precedence over values in this file.
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate an app key if one is not supplied via the environment or .env.
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64' .env 2>/dev/null; then
    php artisan key:generate --force || true
fi

# Wait for the database to accept connections (best-effort)
if [ "${DB_CONNECTION:-}" != "sqlite" ] && [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for database at ${DB_HOST}:${DB_PORT:-3306}..."
    for i in $(seq 1 30); do
        if php -r "exit(@fsockopen(getenv('DB_HOST'), (int)(getenv('DB_PORT') ?: 3306)) ? 0 : 1);"; then
            echo "Database is up."
            break
        fi
        sleep 2
    done
fi

# Only the primary web/app container should run migrations & cache warmup.
# Workers/scheduler set RUN_MIGRATIONS=false to avoid races.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force || true
    php artisan storage:link || true

    # Cache config/routes/views for performance (safe to ignore if it fails in dev)
    if [ "${APP_ENV:-production}" = "production" ]; then
        php artisan config:cache || true
        php artisan route:cache || true
        php artisan view:cache || true
    else
        php artisan config:clear || true
    fi
fi

exec "$@"
