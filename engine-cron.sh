#!/bin/bash

# Переходим в директорию проекта
cd "$(dirname "$0")" || exit 1

echo "Starting FlareSolverr..."
docker start flaresolverr > /dev/null

# Ждем 15 секунд, чтобы браузер успел подняться
sleep 15

echo "Running TorrentMonitor engine..."
docker exec torrentmonitor php engine.php

echo "Stopping FlareSolverr..."
docker stop flaresolverr > /dev/null

echo "Backing up database to dots repository..."
if [ -d "$HOME/Lab/dots/backups" ] && [ -f "db/torrentmonitor.sqlite" ]; then
    cp db/torrentmonitor.sqlite "$HOME/Lab/dots/backups/torrentmonitor.sqlite"
fi

echo "Done."
