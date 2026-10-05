FROM php:8.3-apache

# libsqlite3-dev is here for pdo_sqlite, which is compiled below.
#
# The base image carries SQLite's runtime library but not its headers, so
# the extension cannot be built without it and the whole apt/ext-install
# layer exits 1 - which is a deploy that never ships rather than an app
# that starts and then cannot reach its database.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    zip \
    libsqlite3-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        bcmath \
        gd \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN sed -i 's#/var/www/html#/var/www/html/public#g' \
    /etc/apache2/sites-available/000-default.conf

RUN printf '<Directory /var/www/html/public>\n\
AllowOverride All\n\
Require all granted\n\
</Directory>\n' \
    >> /etc/apache2/apache2.conf

# The database arrives as a Render secret file, never through git.
#
# This repository is PUBLIC. The database holds every client, payment and
# message in the shop, so committing it would publish all of it to anyone who
# finds the URL, and git history keeps it after a delete. Render mounts secret
# files at /etc/secrets, which the repo never sees.
#
# Gzipped and base64'd because a secret file is plaintext and capped at 1 MB:
# 2.4 MB of database becomes 642 KB of text, which fits.
COPY docker-start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# The DIRECTORY has to be writable, not just the file: SQLite puts its -wal
# and -journal beside the database, so a writable file in a read-only folder
# still fails the moment anybody saves anything.
RUN chown -R www-data:www-data /var/www/html/database \
    && chmod -R 775 /var/www/html/database

EXPOSE 10000

CMD ["/usr/local/bin/start.sh"]
