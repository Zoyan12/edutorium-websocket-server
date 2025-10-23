#!/bin/bash
# Health check script for Edutorium WebSocket Server

# Try to connect to the health endpoint on port 8080
if curl -f http://localhost:8080/health > /dev/null 2>&1; then
    exit 0
elif wget --no-verbose --tries=1 --spider http://localhost:8080/health > /dev/null 2>&1; then
    exit 0
else
    # Check if the PHP process is running
    if pgrep -f "php battle-server.php" > /dev/null; then
        exit 0
    else
        exit 1
    fi
fi
