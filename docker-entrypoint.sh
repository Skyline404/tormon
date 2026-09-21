#!/bin/bash
set -e

# Create config.php if missing
if [ ! -f /var/www/html/config.php ]; then
    echo "Creating default config.php for SQLite..."
    cat << 'PHP_EOF' > /var/www/html/config.php
<?php
include_once dirname(__FILE__).'/class/Config.class.php';
Config::extended();

Config::write('db.type', 'sqlite');
Config::write('db.basename', '/var/www/html/db/torrentmonitor.sqlite');
?>
PHP_EOF
fi

# Initialize SQLite database if it doesn't exist or is empty
DB_FILE="/var/www/html/db/torrentmonitor.sqlite"
if [ ! -f "$DB_FILE" ] || [ ! -s "$DB_FILE" ]; then
    echo "Initializing database schema..."
    php -r "\$db = new PDO('sqlite:$DB_FILE'); \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); \$db->exec(file_get_contents('/var/www/html/db_schema/sqlite.sql'));"
fi

exec apache2-foreground
