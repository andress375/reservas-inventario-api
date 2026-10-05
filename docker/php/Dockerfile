FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends unzip git \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
