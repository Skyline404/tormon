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

echo "Done."
