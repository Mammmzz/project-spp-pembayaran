#!/bin/bash
set -e

echo "🚀 Starting Laravel application..."

# Wait for database to be ready
echo "⏳ Waiting for database connection..."
php artisan tinker --execute="
try {
    DB::connection()->getPdo();
    echo 'Database connected!';
} catch (Exception \$e) {
    echo 'Waiting for database...';
    sleep(5);
}
" || sleep 10

# Generate application key if not set
if [ -z "$APP_KEY" ]; then
    echo "🔑 Generating application key..."
    php artisan key:generate --force
fi

# Run migrations
echo "📊 Running database migrations..."
php artisan migrate --force

# Run seeders (only on first deploy)
if [ "$RUN_SEEDERS" = "true" ]; then
    echo "🌱 Running database seeders..."
    php artisan db:seed --force
fi

# Cache configuration
echo "⚙️ Caching configuration..."
php artisan config:cache
php artisan route:cache

# Create storage link
echo "🔗 Creating storage link..."
php artisan storage:link || true

# Set Apache port from Railway
export APACHE_PORT=${PORT:-80}
sed -i "s/Listen 80/Listen ${APACHE_PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${APACHE_PORT}/" /etc/apache2/sites-available/000-default.conf

echo "✅ Application ready!"
echo "🌐 Starting Apache on port ${APACHE_PORT}..."

# Start Apache
apache2-foreground
