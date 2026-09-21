FROM php:8.3-cli-bookworm
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev unzip && docker-php-ext-install pdo_pgsql mbstring && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY apps/api/ ./
COPY docs/handoff/fixtures/pilot.json ./fixtures/pilot.json
RUN composer install --no-dev --no-interaction --no-scripts --no-plugins --prefer-dist --optimize-autoloader
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
