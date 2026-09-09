FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        libicu-dev \
        libonig-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl mbstring mysqli opcache pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite \
    && a2dissite 000-default

COPY docker/apache-cliniq.conf /etc/apache2/sites-available/cliniq.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/cliniq-production.ini
RUN a2ensite cliniq

WORKDIR /var/www/html
COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/cliniq-entrypoint

RUN chmod 0755 /usr/local/bin/cliniq-entrypoint /var/www/html/scripts/backup/container_scheduler.sh \
    && mkdir -p /var/www/html/storage/documents/ape /var/www/html/public/uploads /var/backups/cliniq \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/public/uploads /var/backups/cliniq

ENTRYPOINT ["/usr/local/bin/cliniq-entrypoint"]
CMD ["apache2-foreground"]
