#!/bin/bash
echo "=== Rebuilding Database with Foreign Key Support ==="
echo ""
DB_PATH="/var/www/database/tickers.db"
if [ -f "$DB_PATH" ]; then
    echo "[1/4] Removing old database..."
    rm -f "$DB_PATH"
    echo "✓ Old database removed"
else
    echo "[1/4] No existing database found"
fi
echo ""
echo "[2/4] Running migration..."
php /var/www/database/migrate.php
echo ""
echo "[3/4] Importing existing tickers..."
php /var/www/database/import-existing-tickers.php
echo ""
echo "[4/4] Running tests..."
php /var/www/database/test-repository.php
echo ""
echo "=== Rebuild Complete ==="
