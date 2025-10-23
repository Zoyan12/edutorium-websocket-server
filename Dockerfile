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
    wget \
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

# Copy and setup health check script
COPY healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh

# Create logs directory
RUN mkdir -p logs && chmod 755 logs

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod +x battle-server.php

# Create startup script
RUN echo '#!/bin/bash\n\
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

# Expose WebSocket port and HTTP health check port
EXPOSE 3000 8080

# Health check using custom script
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh

# Start WebSocket server
CMD ["/usr/local/bin/start-websocket.sh"]
