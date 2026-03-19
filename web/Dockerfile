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

# Cron installieren
RUN apt-get update && apt-get install -y cron && rm -rf /var/lib/apt/lists/*

# Quellcode kopieren und Berechtigungen fuer www-data setzen
COPY . /var/www/
RUN chown -R www-data:www-data /var/www \
    && find /var/www -type d -exec chmod 755 {} \; \
    && find /var/www -type f -exec chmod 644 {} \;

# Cron-Job: CMDB Sync stündlich (sourced env vars)
RUN echo '0 * * * * root . /etc/environment; /usr/local/bin/php /var/www/cron/sync-customers.php >> /var/log/cron-sync.log 2>&1' > /etc/cron.d/cmdb-sync \
    && chmod 644 /etc/cron.d/cmdb-sync

# Startup: export env vars for cron, then start cron + Apache
RUN printf '#!/bin/bash\n\
# Pass environment variables to cron\n\
printenv | grep -E "^(PG|VAULT_KEY|ENTRA_)" > /etc/environment\n\
cron\n\
exec apache2-foreground\n' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

EXPOSE 80
CMD ["/usr/local/bin/start.sh"]
