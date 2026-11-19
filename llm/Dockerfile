# Both LLM examples in one container: Apache + PHP + SQLite.
#
#   docker compose up --build                  -> http://localhost:8080/
# or without Compose:
#   docker build -t parcelshop-llm .
#   docker run --rm -p 8080:80 -e ANTHROPIC_API_KEY parcelshop-llm
#
# The API key is never part of the image: it comes from the environment when the container starts.

# ---- build stage: Composer installs the libraries (composer.lock = the exact versions) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

# ---- runtime stage: only what runs ----
FROM php:8.4-apache

# The .htaccess files hide vendor/, data/ and the config files, so Apache must read them.
RUN a2enmod rewrite \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .

# data/ is the only folder the app writes to: the SQLite database and the MCP sessions.
# The database is created from data/schema.sql on the first request.
RUN mkdir -p data/mcp-sessions && chown -R www-data:www-data data

# chat.php calls rpc.php and server.php inside this container, whatever the public address is
# (behind a cloud platform's proxy the public URL would be https://… on another port).
ENV LLM_RPC_URL=http://localhost/01-tool-calling/api/rpc.php \
    LLM_MCP_URL=http://localhost/02-mcp/server.php

# Docker (and most platforms) restart or stop routing to a container whose health check fails.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD php -r 'exit(@file_get_contents("http://localhost/healthz.php") === false ? 1 : 0);'
