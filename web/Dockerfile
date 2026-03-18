FROM php:8.3-apache

# PHP-Extensions installieren (pdo_pgsql, gd, zip fuer PhpSpreadsheet)
RUN apt-get update \
    && apt-get install -y libpq-dev unzip libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql gd zip \
    && rm -rf /var/lib/apt/lists/*

# Composer installieren
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache: mod_rewrite aktivieren
RUN a2enmod rewrite

# Composer-Abhaengigkeiten installieren
WORKDIR /var/www
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader

# Apache DocumentRoot auf public/ setzen
ENV APACHE_DOCUMENT_ROOT=/var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf

# Quellcode kopieren
COPY . /var/www/

EXPOSE 80
