#!/bin/sh
# What the container does before Apache answers anything.
#
# It used to be a one-line CMD that only fixed the port, so the app started
# with no database at all and every page that touched one returned 500 - which
# is what /login was doing.
set -e

DB=/var/www/html/database/imprint.sqlite
SECRET=/etc/secrets/imprint.sqlite.b64

if [ -f "$SECRET" ]; then
    echo "Restoring the database from the secret file..."
    base64 -d < "$SECRET" | gunzip > "$DB"
else
    echo "No secret file found - starting on an empty database."
    touch "$DB"
fi

# The file AND the folder: SQLite writes its -wal and -journal beside the
# database, so a writable file in a read-only folder fails on the first save.
chown www-data:www-data "$DB"
chmod 664 "$DB"
chown www-data:www-data /var/www/html/database

# Brings an empty database up; a no-op on a restored one that is already
# current. Never --seed: this must not invent data on a real copy.
php artisan migrate --force --no-interaction

php artisan config:cache
php artisan route:cache

# Render hands the port in at runtime, so this cannot be baked into the image.
sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
