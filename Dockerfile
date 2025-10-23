# Standalone WebSocket Server Dockerfile
FROM composer:2.6 AS composer-stage

WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    curl \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    curl \
    sockets

# Set working directory
WORKDIR /var/www/html

# Copy composer dependencies from build stage
COPY --from=composer-stage /app/vendor ./vendor

# Copy application files
COPY . .

# Create logs directory
RUN mkdir -p logs && chmod 755 logs

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod +x battle-server.php

# Create startup script
RUN echo '#!/bin/bash\n\
set -e\n\
\n\
echo "Starting Edutorium WebSocket Server..."\n\
echo "Environment: ${APP_ENV:-production}"\n\
echo "Port: ${WEBSOCKET_PORT:-3000}"\n\
echo "Supabase URL: ${SUPABASE_URL}"\n\
echo ""\n\
\n\
# Start WebSocket server\n\
cd /var/www/html\n\
exec php battle-server.php' > /usr/local/bin/start-websocket.sh

RUN chmod +x /usr/local/bin/start-websocket.sh

# Expose WebSocket port
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:3000/health || exit 1

# Start WebSocket server
CMD ["/usr/local/bin/start-websocket.sh"]
