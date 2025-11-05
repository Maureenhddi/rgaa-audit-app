#!/bin/bash
set -e

echo "=== RGAA Audit - Startup Script ==="

# Installer les dépendances Composer si nécessaire
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist || echo "Composer install failed, continuing..."
fi

# Installer les dépendances Node.js si nécessaire
if [ -d "audit-scripts" ] && [ ! -d "audit-scripts/node_modules" ]; then
    echo "Installing Node.js dependencies..."
    cd audit-scripts
    npm install || echo "npm install failed, continuing..."
    cd ..
fi

# Installer les navigateurs Playwright si nécessaire
if [ -d "audit-scripts" ]; then
    PLAYWRIGHT_CACHE="${PLAYWRIGHT_BROWSERS_PATH:-/var/lib/playwright}"
    # Créer et définir les permissions du répertoire
    mkdir -p "$PLAYWRIGHT_CACHE"
    chown -R www-data:www-data "$PLAYWRIGHT_CACHE" 2>/dev/null || true

    if [ ! -d "$PLAYWRIGHT_CACHE" ] || [ -z "$(ls -A $PLAYWRIGHT_CACHE 2>/dev/null)" ]; then
        echo "Installing Playwright browsers to $PLAYWRIGHT_CACHE..."
        cd audit-scripts
        # Utiliser le CLI de Playwright directement pour installer la bonne version
        ./node_modules/playwright/cli.js install chromium 2>&1 | grep -E "(Downloaded|playwright build)" || true
        cd ..
        # S'assurer que www-data peut accéder aux navigateurs
        chown -R www-data:www-data "$PLAYWRIGHT_CACHE" 2>/dev/null || true
    else
        echo "Playwright browsers already installed in $PLAYWRIGHT_CACHE"
    fi
fi

# Créer les répertoires nécessaires
mkdir -p var/cache var/log var/sessions
chown -R www-data:www-data var 2>/dev/null || true
chmod -R 775 var 2>/dev/null || true

# Attendre que la base de données soit prête (optionnel)
if [ "${SKIP_DB_CHECK:-0}" != "1" ]; then
    echo "Waiting for database..."
    MAX_RETRIES=15
    RETRY_COUNT=0
    until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1 || [ $RETRY_COUNT -eq $MAX_RETRIES ]; do
        echo "Database is unavailable - sleeping ($RETRY_COUNT/$MAX_RETRIES)"
        sleep 2
        RETRY_COUNT=$((RETRY_COUNT + 1))
    done

    if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
        echo "Warning: Could not connect to database after $MAX_RETRIES attempts. Continuing anyway..."
    else
        echo "Database is up!"

        # Exécuter les migrations si en mode dev ou si la variable FORCE_MIGRATIONS est définie
        if [ "$APP_ENV" = "dev" ] || [ "$FORCE_MIGRATIONS" = "1" ]; then
            echo "Running database migrations..."
            php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true
        fi
    fi
else
    echo "Skipping database check (SKIP_DB_CHECK=1)"
fi

# Vider et réchauffer le cache
echo "Clearing cache..."
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

# Installer les assets
if [ "$APP_ENV" = "dev" ]; then
    echo "Installing assets..."
    php bin/console assets:install public || true
fi

echo "Application is ready!"

# Exécuter la commande passée au conteneur
exec "$@"
