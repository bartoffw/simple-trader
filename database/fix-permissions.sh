#!/bin/bash

# Fix Database Permissions Script
# Sets proper ownership and permissions for Apache to write to SQLite database

echo "=== Fixing Database Permissions ==="
echo ""

# Paths
DB_DIR="/var/www/database"
DB_FILE="$DB_DIR/tickers.db"

echo "[1/3] Setting ownership to www-data..."
chown -R www-data:www-data "$DB_DIR"
echo "✓ Ownership set to www-data:www-data"

echo ""
echo "[2/3] Setting directory permissions..."
chmod 775 "$DB_DIR"
echo "✓ Directory permissions: 775 (rwxrwxr-x)"

echo ""
echo "[3/3] Setting file permissions..."
if [ -f "$DB_FILE" ]; then
    chmod 664 "$DB_FILE"
    echo "✓ Database file permissions: 664 (rw-rw-r--)"
else
    echo "⚠ Database file not found (will be created with correct permissions)"
fi

echo ""
echo "=== Current Permissions ==="
ls -lah "$DB_DIR" | head -10

echo ""
echo "=== Permissions Fixed ==="
echo "Apache (www-data) can now write to the database."
