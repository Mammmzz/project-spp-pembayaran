#!/bin/bash
set -e

echo "🚀 Starting Laravel application..."

# Generate application key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:GENERATE_THIS_LATER" ]; then
    echo "🔑 Generating application key..."
    php artisan key:generate --force
    export APP_KEY=$(grep APP_KEY .env | cut -d '=' -f2)
    echo "✅ APP_KEY generated: ${APP_KEY:0:20}..."
fi

# Set Apache port from Railway BEFORE starting anything
export APACHE_PORT=${PORT:-80}
echo "🔧 Configuring Apache for port ${APACHE_PORT}..."
sed -i "s/Listen 80/Listen ${APACHE_PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:.*>/<VirtualHost *:${APACHE_PORT}>/" /etc/apache2/sites-available/000-default.conf

# Create required directories
mkdir -p storage/framework/{sessions,views,cache}
mkdir -p bootstrap/cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Wait for database to be ready (with retry)
echo "⏳ Waiting for database connection..."
MAX_RETRIES=10
RETRY_COUNT=0
until php artisan tinker --execute="DB::connection()->getPdo(); echo 'OK';" 2>/dev/null | grep -q "OK"; do
    RETRY_COUNT=$((RETRY_COUNT+1))
    if [ $RETRY_COUNT -ge $MAX_RETRIES ]; then
        echo "⚠️  Database connection failed after ${MAX_RETRIES} attempts"
        echo "⚠️  Starting without database (will retry on requests)"
        break
    fi
    echo "   Attempt ${RETRY_COUNT}/${MAX_RETRIES}... retrying in 3s"
    sleep 3
done

if [ $RETRY_COUNT -lt $MAX_RETRIES ]; then
    echo "✅ Database connected!"
    
    # Run migrations
    echo "📊 Running database migrations..."
    php artisan migrate --force || echo "⚠️  Migration failed, continuing..."
    
    # Run seeders (only on first deploy)
    if [ "$RUN_SEEDERS" = "true" ]; then
        echo "🌱 Running database seeders..."
        php artisan db:seed --force || echo "⚠️  Seeding failed, continuing..."
    fi
fi

# Cache configuration
echo "⚙️ Caching configuration..."
php artisan config:cache || true
php artisan route:cache || true

# Create storage link
echo "🔗 Creating storage link..."
php artisan storage:link || true

# Run package discovery
echo "📦 Discovering packages..."
php artisan package:discover --ansi || true

echo "✅ Application ready!"
echo "🌐 Starting Apache on port ${APACHE_PORT}..."

# Start Apache in foreground
exec apache2-foreground
