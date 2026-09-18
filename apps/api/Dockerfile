FROM php:8.3-cli-bookworm
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY apps/api/public ./public
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
