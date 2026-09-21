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
    export DB_FILE="$DB_FILE"
    
    echo "Setting default values for Docker environment..."
    php -r "\$db = new PDO('sqlite:' . getenv('DB_FILE')); \$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
    \$db->exec(\"UPDATE settings SET val = '1' WHERE key = 'useTorrent';\");
    \$db->exec(\"UPDATE settings SET val = 'qBittorrent' WHERE key = 'torrentClient';\");
    \$db->exec(\"UPDATE settings SET val = 'http://host.docker.internal:8090' WHERE key = 'torrentAddress';\");
    \$db->exec(\"UPDATE settings SET val = '' WHERE key = 'pathToDownload';\");
    \$db->exec(\"UPDATE settings SET val = 'http://flaresolverr:8191' WHERE key = 'flaresolverrUrl';\");
    \$db->exec(\"UPDATE settings SET val = 'http://localhost:7060/' WHERE key = 'serverAddress';\");
    \$db->exec(\"UPDATE settings SET val = '120' WHERE key = 'httpTimeout';\");"
fi

exec apache2-foreground
