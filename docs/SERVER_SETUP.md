# Simple-Trader Server Setup Guide

This document provides step-by-step instructions for setting up Simple-Trader on a fresh server installation.

## Table of Contents

1. [Server Requirements](#server-requirements)
2. [Installation Steps](#installation-steps)
3. [Database Setup](#database-setup)
4. [Configuration](#configuration)
5. [Web Server Setup](#web-server-setup)
6. [Cron Job Configuration](#cron-job-configuration)
7. [Permissions and Security](#permissions-and-security)
8. [Testing the Installation](#testing-the-installation)
9. [Troubleshooting](#troubleshooting)

---

## Server Requirements

### Minimum Requirements

- **PHP**: 8.1 or higher
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Operating System**: Linux (Ubuntu 20.04+, Debian 11+, CentOS 8+, or similar)
- **Memory**: 512 MB RAM minimum, 1 GB+ recommended
- **Disk Space**: 1 GB minimum for application and databases
- **Network**: Internet access for fetching quote data

### Required PHP Extensions

```bash
php -m | grep -E 'PDO|sqlite3|mbstring|json|curl|openssl|xml|dom'
```

The following PHP extensions must be installed:

- `pdo` - Database abstraction layer
- `pdo_sqlite` - SQLite database driver
- `sqlite3` - SQLite support
- `mbstring` - Multibyte string handling
- `json` - JSON support
- `curl` - HTTP client for fetching quotes
- `openssl` - SSL/TLS support
- `xml` - XML parsing
- `dom` - DOM manipulation
- `fileinfo` - File type detection
- `filter` - Data filtering

### Installing PHP and Extensions

**Ubuntu/Debian:**
```bash
sudo apt update
sudo apt install -y php8.1 php8.1-cli php8.1-common php8.1-sqlite3 \
    php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip
```

**CentOS/RHEL:**
```bash
sudo dnf install -y php php-cli php-common php-pdo php-sqlite3 \
    php-curl php-mbstring php-xml php-zip
```

### Composer

Composer is required for dependency management:

```bash
# Download and install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Verify installation
composer --version
```

---

## Installation Steps

### 1. Clone the Repository

```bash
cd /var/www
sudo git clone https://github.com/your-org/simple-trader.git
cd simple-trader
```

### 2. Set Ownership

```bash
# Replace 'www-data' with your web server user (nginx, apache, etc.)
sudo chown -R www-data:www-data /var/www/simple-trader
```

### 3. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

For development environments, omit `--no-dev`:
```bash
composer install
```

### 4. Create Required Directories

```bash
# Create database directory
mkdir -p database

# Create var directory for lock files and logs
mkdir -p var

# Create cache directory
mkdir -p cache

# Set permissions
chmod 755 database var cache
```

---

## Database Setup

### 1. Run Database Migrations

Simple-Trader uses three separate SQLite databases. Run migrations for each:

```bash
# Tickers database
php commands/migrate.php tickers

# Backtests database (backtests)
php commands/migrate.php backtests

# Monitors database (strategy monitoring)
php commands/migrate.php monitors
```

### 2. Verify Database Files

```bash
ls -lh database/*.db
```

You should see:
- `database/tickers.db` - Ticker and quote data
- `database/backtests.db` - Backtest run history
- `database/monitors.db` - Strategy monitor configuration and history

### 3. Set Database Permissions

```bash
chmod 644 database/*.db
chown www-data:www-data database/*.db
```

---

## Configuration

### 1. Create Environment File

Copy the example environment file and configure:

```bash
cp .env.example .env
nano .env
```

### 2. Configure Environment Variables

Edit `.env` with your settings:

```bash
# Application Settings
APP_NAME="Simple Trader"
APP_ENV=production
APP_DEBUG=false

# SMTP Configuration (for email notifications)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
FROM_EMAIL=your-email@gmail.com
TO_EMAIL=notifications@yourcompany.com

# Data Source Configuration
# (Add your quote data source API keys here)
YAHOO_FINANCE_API_KEY=your-api-key
TRADINGVIEW_API_KEY=your-api-key
```

### 3. Set Environment File Permissions

```bash
chmod 600 .env
chown www-data:www-data .env
```

**Important**: Never commit `.env` to version control. It contains sensitive credentials.

---

## Web Server Setup

### Apache Configuration

Create a virtual host configuration:

```bash
sudo nano /etc/apache2/sites-available/simple-trader.conf
```

Add the following configuration:

```apache
<VirtualHost *:80>
    ServerName trader.example.com
    DocumentRoot /var/www/simple-trader/public

    <Directory /var/www/simple-trader/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Slim Framework URL rewriting
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]
    </Directory>

    # Deny access to sensitive directories
    <Directory /var/www/simple-trader/database>
        Require all denied
    </Directory>

    <Directory /var/www/simple-trader/config>
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/simple-trader-error.log
    CustomLog ${APACHE_LOG_DIR}/simple-trader-access.log combined
</VirtualHost>
```

Enable the site and required modules:

```bash
sudo a2enmod rewrite
sudo a2ensite simple-trader
sudo systemctl restart apache2
```

### Nginx Configuration

Create a server block configuration:

```bash
sudo nano /etc/nginx/sites-available/simple-trader
```

Add the following configuration:

```nginx
server {
    listen 80;
    server_name trader.example.com;
    root /var/www/simple-trader/public;
    index index.php;

    # Logging
    access_log /var/log/nginx/simple-trader-access.log;
    error_log /var/log/nginx/simple-trader-error.log;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM configuration
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ ^/(database|config|vendor|commands|src) {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/simple-trader /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

---

## Cron Job Configuration

Simple-Trader requires cron jobs for automated daily updates. These jobs update ticker quotes and process strategy monitors.

### 1. Edit Crontab

```bash
sudo crontab -e -u www-data
```

**Note**: Run cron jobs as the web server user (www-data, nginx, or apache) to ensure proper permissions.

### 2. Add Cron Entries

#### Option A: Single Master Job (Recommended)

Run the master dispatcher that executes all updates sequentially:

```cron
# Run daily update at 4:30 PM ET (after US market close)
# Monday-Friday only
30 16 * * 1-5 cd /var/www/simple-trader && php commands/daily-update.php >> /var/log/simple-trader/daily-update.log 2>&1
```

#### Option B: Separate Jobs

Run quote and monitor updates as separate cron jobs:

```cron
# Update ticker quotes at 4:15 PM ET
15 16 * * 1-5 cd /var/www/simple-trader && php commands/update-quotes.php >> /var/log/simple-trader/quotes.log 2>&1

# Update strategy monitors at 4:30 PM ET (after quotes are updated)
30 16 * * 1-5 cd /var/www/simple-trader && php commands/update-monitor.php >> /var/log/simple-trader/monitors.log 2>&1
```

### 3. Create Log Directory

```bash
sudo mkdir -p /var/log/simple-trader
sudo chown www-data:www-data /var/log/simple-trader
sudo chmod 755 /var/log/simple-trader
```

### 4. Log Rotation

Create a logrotate configuration to prevent log files from growing too large:

```bash
sudo nano /etc/logrotate.d/simple-trader
```

Add the following configuration:

```
/var/log/simple-trader/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0640 www-data www-data
    sharedscripts
}
```

### 5. Test Cron Jobs Manually

Before relying on automated execution, test each command manually:

```bash
# Test quote updates
cd /var/www/simple-trader
sudo -u www-data php commands/update-quotes.php

# Test monitor updates
sudo -u www-data php commands/update-monitor.php

# Test master dispatcher
sudo -u www-data php commands/daily-update.php
```

### 6. Verify Cron Execution

After the scheduled time, check the logs:

```bash
tail -f /var/log/simple-trader/daily-update.log
```

---

## Permissions and Security

### Directory Permissions

```bash
# Application root
chmod 755 /var/www/simple-trader

# Public directory
chmod 755 /var/www/simple-trader/public

# Database directory (readable/writable by web server)
chmod 755 /var/www/simple-trader/database
chmod 644 /var/www/simple-trader/database/*.db

# Var directory (for lock files)
chmod 755 /var/www/simple-trader/var
chmod 644 /var/www/simple-trader/var/*.lock

# Cache directory
chmod 755 /var/www/simple-trader/cache

# Configuration files
chmod 600 /var/www/simple-trader/.env
chmod 644 /var/www/simple-trader/config/*.php

# Command scripts (executable)
chmod 755 /var/www/simple-trader/commands/*.php
```

### File Ownership

All files should be owned by the web server user:

```bash
sudo chown -R www-data:www-data /var/www/simple-trader
```

### Security Hardening

1. **Disable directory listing:**
   - Already configured in web server settings above

2. **Restrict access to sensitive files:**
   - Database files should not be accessible via web
   - Configuration files should not be accessible via web
   - Lock files should not be accessible via web

3. **Use HTTPS in production:**
   ```bash
   # Install Certbot for Let's Encrypt SSL
   sudo apt install certbot python3-certbot-apache
   sudo certbot --apache -d trader.example.com
   ```

4. **Firewall configuration:**
   ```bash
   # Allow HTTP and HTTPS
   sudo ufw allow 80/tcp
   sudo ufw allow 443/tcp
   sudo ufw enable
   ```

5. **Keep software updated:**
   ```bash
   # Regular updates
   sudo apt update && sudo apt upgrade
   composer update
   ```

---

## Testing the Installation

### 1. Test Web Interface

Visit your domain in a web browser:
```
http://trader.example.com
```

You should see the Simple-Trader dashboard.

### 2. Test Database Connectivity

```bash
php -r "require 'vendor/autoload.php'; \$db = SimpleTrader\Database\Database::getInstance('database/tickers.db'); echo 'Database connected successfully';"
```

### 3. Test Quote Updates

```bash
cd /var/www/simple-trader

# Test with help flag
php commands/update-quotes.php --help

# Test actual execution (if you have tickers configured)
php commands/update-quotes.php
```

Expected output:
```
=== Update Ticker Quotes ===
Started: 2025-01-15 16:15:00

Updating all enabled tickers

[Update progress...]

=== Summary ===
Total tickers: 5
✓ Succeeded: 5
✗ Failed: 0

Completed: 2025-01-15 16:15:45
```

### 4. Test Monitor Updates

```bash
# Test with help flag
php commands/update-monitor.php --help

# Test actual execution (if you have monitors configured)
php commands/update-monitor.php
```

### 5. Test Master Dispatcher

```bash
# Test with help flag
php commands/daily-update.php --help

# Test actual execution
php commands/daily-update.php
```

### 6. Test Lock File Prevention

Run the same command twice simultaneously:

```bash
# Terminal 1
php commands/update-quotes.php &

# Terminal 2 (immediately after)
php commands/update-quotes.php
```

Expected output from Terminal 2:
```
✗ Another instance is already running
```

### 7. Test Email Notifications

If SMTP is configured, run a command and verify email delivery:

```bash
php commands/daily-update.php
```

Check your configured `TO_EMAIL` for the notification.

---

## Troubleshooting

### Common Issues

#### 1. "Permission denied" errors

**Symptom**: Cannot write to database or create lock files

**Solution**:
```bash
sudo chown -R www-data:www-data /var/www/simple-trader
chmod 755 /var/www/simple-trader/database
chmod 755 /var/www/simple-trader/var
```

#### 2. "Class not found" errors

**Symptom**: PHP cannot find SimpleTrader classes

**Solution**:
```bash
composer dump-autoload --optimize
```

#### 3. Cron jobs not running

**Symptom**: No log files created, updates not happening

**Solution**:
- Verify crontab: `sudo crontab -l -u www-data`
- Check cron service: `sudo systemctl status cron`
- Test manually: `sudo -u www-data php commands/daily-update.php`
- Check system logs: `grep CRON /var/log/syslog`

#### 4. Database locked errors

**Symptom**: "Database is locked" when running commands

**Solution**:
- Ensure only one process accesses database at a time
- Lock files should prevent this - check if lock files are being created
- Verify permissions on var directory
- Check if concurrent processes are running: `ps aux | grep php`

#### 5. Email notifications not sending

**Symptom**: Commands run successfully but no emails received

**Solution**:
- Verify SMTP credentials in `.env`
- Test SMTP connection manually
- Check spam/junk folder
- Review command output for email errors
- Ensure `getenv()` is enabled in PHP

#### 6. "Another instance is already running"

**Symptom**: Command exits immediately with lock file error

**Solution**:
- Check if lock file exists: `ls -la /var/www/simple-trader/var/*.lock`
- Check if process is actually running: `ps aux | grep "update-quotes\|update-monitor\|daily-update"`
- If no process running, remove stale lock file: `rm /var/www/simple-trader/var/*.lock`

### Debug Mode

Enable detailed logging for troubleshooting:

```bash
# Run with error reporting
php -d error_reporting=E_ALL -d display_errors=On commands/daily-update.php
```

### Getting Help

1. Check application logs: `/var/log/simple-trader/*.log`
2. Check web server logs: `/var/log/apache2/` or `/var/log/nginx/`
3. Check PHP error logs: `/var/log/php8.1-fpm.log`
4. Check system logs: `/var/log/syslog`

---

## Maintenance

### Regular Tasks

1. **Database Backups** (Daily):
   ```bash
   mkdir -p /var/backups/simple-trader
   cp /var/www/simple-trader/database/*.db /var/backups/simple-trader/
   ```

2. **Log Cleanup** (Automated via logrotate)

3. **Software Updates** (Monthly):
   ```bash
   cd /var/www/simple-trader
   composer update
   sudo systemctl restart apache2  # or nginx
   ```

4. **Monitor Disk Space**:
   ```bash
   df -h /var/www/simple-trader
   du -sh /var/www/simple-trader/database
   ```

### Backup Automation

Add to crontab for daily database backups:

```cron
# Backup databases daily at 2 AM
0 2 * * * cp /var/www/simple-trader/database/*.db /var/backups/simple-trader/$(date +\%Y\%m\%d)/ 2>&1
```

---

## Next Steps

After successful installation:

1. **Add Tickers**: Navigate to Tickers page and add your first ticker symbols
2. **Configure Data Sources**: Set up API keys for quote data providers
3. **Run Initial Quote Fetch**: Manually run `update-quotes.php` to populate historical data
4. **Create Strategy Monitor**: Navigate to Strategy Monitoring and create your first monitor
5. **Monitor Logs**: Watch the first few cron executions to ensure everything works correctly

---

## Additional Resources

- [Project README](../README.md)
- [Daily Update Research](DAILY_UPDATE_RESEARCH.md)
- [Strategy Development Guide](STRATEGY_DEVELOPMENT.md) (if exists)
- [API Documentation](API_DOCUMENTATION.md) (if exists)

---

**Last Updated**: 2025-01-15
**Version**: 1.0
