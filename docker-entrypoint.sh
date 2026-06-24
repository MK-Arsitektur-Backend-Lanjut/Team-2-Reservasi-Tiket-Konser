#!/bin/sh
set -e

# Install PHP dependencies jika vendor belum ada
if [ ! -f "vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

# Generate APP_KEY jika belum ada
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    if [ -f ".env" ]; then
        KEY_LINE=$(grep "^APP_KEY=" .env || true)
        if [ -z "$KEY_LINE" ] || [ "$KEY_LINE" = "APP_KEY=" ]; then
            echo "Generating APP_KEY..."
            php artisan key:generate --force
        fi
    fi
fi

# Jalankan migration
echo "Running migrations..."
php artisan migrate --force 2>/dev/null || true

# Start Octane
echo "Starting Laravel Octane with Swoole..."
exec php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000
