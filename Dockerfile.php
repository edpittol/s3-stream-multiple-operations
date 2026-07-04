FROM php:8.5-cli

# Install Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# Install system dependencies needed by PHP extensions
RUN apt-get update && apt-get install -y \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-interaction --no-progress

ENTRYPOINT ["php"]
