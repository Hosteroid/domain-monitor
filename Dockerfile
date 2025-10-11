# Use PHP 8.1 FPM
FROM php:8.1-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    unzip \
    whois \
    dcron \
    mysql-client \
    oniguruma-dev

# Install PHP extensions required by the application
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    mbstring \
    fileinfo

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install Composer dependencies
RUN composer install --no-interaction --no-dev --optimize-autoloader


# Create necessary directories with proper permissions
RUN mkdir -p logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 logs

# Copy PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy startup script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose FPM port
EXPOSE 8000

# Start PHP-FPM and cron
ENTRYPOINT ["/entrypoint.sh"]