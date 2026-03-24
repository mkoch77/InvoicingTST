FROM php:8.3-apache

# PHP Memory- und Timeout-Limits erhoehen (fuer CMDB-Sync mit grossen Datenmengen)
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/limits.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/limits.ini

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

# Apache DocumentRoot auf public/ setzen + AllowOverride fuer .htaccess
ENV APACHE_DOCUMENT_ROOT=/var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/g' \
    /etc/apache2/apache2.conf

# Cron, pg_dump (Backup), sshpass/lftp (Remote-Backup) installieren
RUN apt-get update && apt-get install -y cron postgresql-client sshpass lftp openssh-client && rm -rf /var/lib/apt/lists/*

# Quellcode kopieren und Berechtigungen fuer www-data setzen
COPY . /var/www/
RUN chown -R www-data:www-data /var/www \
    && find /var/www -type d -exec chmod 755 {} \; \
    && find /var/www -type f -exec chmod 644 {} \;

# Cron-Jobs
RUN echo '0 * * * * root . /etc/environment; /usr/local/bin/php /var/www/cron/sync-customers.php >> /var/log/cron-sync.log 2>&1' > /etc/cron.d/cmdb-sync \
    && echo '30 * * * * root . /etc/environment; /usr/local/bin/php /var/www/cron/sync-companies.php >> /var/log/cron-sync.log 2>&1' >> /etc/cron.d/cmdb-sync \
    && echo '15 * * * * root . /etc/environment; /usr/local/bin/php /var/www/cron/sync-licenses.php >> /var/log/cron-sync.log 2>&1' > /etc/cron.d/entra-sync \
    && echo '45 * * * * root . /etc/environment; /usr/local/bin/php /var/www/cron/sync-devices.php >> /var/log/cron-sync.log 2>&1' >> /etc/cron.d/entra-sync \
    && echo '* * * * * root . /etc/environment; /usr/local/bin/php /var/www/cron/backup.php >> /var/log/cron-backup.log 2>&1' > /etc/cron.d/backup \
    && chmod 644 /etc/cron.d/cmdb-sync /etc/cron.d/entra-sync /etc/cron.d/backup

# Verzeichnis fuer lesbare Secrets-Kopien
RUN mkdir -p /var/www/secrets && chown www-data:www-data /var/www/secrets && chmod 700 /var/www/secrets

# Startup: copy secrets for www-data, export env vars for cron, start cron + Apache
RUN printf '#!/bin/bash\n\
# Copy Docker secrets so www-data can read them\n\
if [ -f /run/secrets/pg_password ]; then\n\
    cp /run/secrets/pg_password /var/www/secrets/pg_password\n\
    chown www-data:www-data /var/www/secrets/pg_password\n\
    chmod 600 /var/www/secrets/pg_password\n\
fi\n\
if [ -f /run/secrets/vault_key ]; then\n\
    cp /run/secrets/vault_key /var/www/secrets/vault_key\n\
    chown www-data:www-data /var/www/secrets/vault_key\n\
    chmod 600 /var/www/secrets/vault_key\n\
fi\n\
# Pass environment variables to cron\n\
printenv | grep -E "^(PG|VAULT_KEY|ENTRA_)" > /etc/environment\n\
cron\n\
exec apache2-foreground\n' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

EXPOSE 80
CMD ["/usr/local/bin/start.sh"]
