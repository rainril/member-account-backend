FROM php:8.2-apache

# System dependencies needed by Composer to download/extract packages
RUN apt-get update && apt-get install -y \
    unzip \
    zip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install mysqli extension (needed for prepare/bind_param/get_result in login_api.php)
RUN docker-php-ext-install mysqli

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer (needed since vendor/ is gitignored -- we install fresh here)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# IMPORTANT: by default PHP's variables_order excludes "E" (Environment),
# so $_ENV[...] stays EMPTY even though Render correctly sets env vars.
# This fixes that so config.php / db_connect.php can read $_ENV['DB_HOST'], etc.
RUN echo "variables_order = EGPCS" > /usr/local/etc/php/conf.d/variables-order.ini

WORKDIR /var/www/html

# Copy composer files first (Docker layer caching: only re-installs if these change)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Now copy the rest of the backend code
COPY . .

# Render assigns a dynamic port via $PORT env var.
# Apache defaults to port 80, so we point it at $PORT instead.
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

EXPOSE 10000

CMD ["apache2-foreground"]
