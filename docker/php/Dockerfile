FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends unzip git \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
# No revelar la versión de PHP en la cabecera X-Powered-By.
RUN echo "expose_php = Off" > /usr/local/etc/php/conf.d/security.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
