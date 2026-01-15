#!/bin/bash
set -e

echo "🚀 Starting Laravel application..."
echo "📍 Environment: ${APP_ENV:-production}"
echo "🔧 Auto-migrate: ${AUTO_MIGRATE:-false}"

# Wait for MySQL to be ready
echo "⏳ Waiting for MySQL to be ready..."
until php artisan db:monitor --quiet 2>/dev/null || mysql -h"${DB_HOST}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" -e "SELECT 1" &>/dev/null; do
    echo "   MySQL is unavailable - sleeping"
    sleep 2
done

echo "✅ MySQL is ready!"

# Only run migrations if AUTO_MIGRATE=true (default: false for safety)
if [ "${AUTO_MIGRATE}" = "true" ]; then
    echo "🔧 AUTO_MIGRATE=true - running automatic setup..."
    
    # Setup database permissions for multi-tenancy
    # In production, DB grants should be handled by infrastructure (DBA / init scripts).
    # We only auto-attempt permission setup in local/testing to avoid noisy logs.
    if [ "${APP_ENV}" = "local" ] || [ "${APP_ENV}" = "testing" ]; then
        echo "🔐 Setting up database permissions (local/testing)..."
        php artisan db:setup-permissions || echo "⚠️  Permission setup skipped (may already exist)"
    else
        echo "🔐 Skipping automatic permission setup (APP_ENV=${APP_ENV})"
    fi
    
    # Run central database migrations
    echo "📦 Running central database migrations..."
    php artisan migrate --force --no-interaction
else
    echo "🛡️  AUTO_MIGRATE not enabled - skipping automatic migrations"
    echo "💡 To enable: set AUTO_MIGRATE=true in .env or docker-compose.yml"
    echo "⚠️  Run migrations manually: docker-compose exec app php artisan migrate"
fi

# Clear caches (safe for multi-tenancy)
echo "🧹 Clearing caches (multi-tenant safe)..."
php artisan optimize:clear

echo "✅ Laravel application ready!"

# Start PHP-FPM or custom command
# If arguments are provided (e.g., "php artisan queue:work"), run those instead
if [ $# -gt 0 ]; then
    echo "🚀 Running custom command: $@"
    exec "$@"
else
    echo "🚀 Starting PHP-FPM..."
    exec php-fpm
fi
