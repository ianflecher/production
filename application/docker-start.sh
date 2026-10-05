#!/bin/sh
# What the container does before Apache answers anything.
set -e

DB=/var/www/html/database/imprint.sqlite
# Render caps a secret file at 500 KiB, so the packed database arrives in
# numbered parts and is joined here. A single unsplit file still works.
SECRET=/tmp/imprint.sqlite.b64
cat /etc/secrets/imprint.sqlite.b64.?? /etc/secrets/imprint.sqlite.b64 > "$SECRET" 2>/dev/null || true

restore_failed() {
    echo "----------------------------------------------------------------"
    echo "The secret file did not decode: $1"
    echo ""
    echo "It holds $(tr -d '[:space:]' < "$SECRET" | wc -c) characters."
    echo "Compare that with the number export-db-for-render.ps1 printed. If it"
    echo "is smaller, the paste was cut short and nothing else is wrong."
    echo ""
    echo "It is pasted into a browser text box, and 642 KB is a lot to paste."
    echo "Truncation is the usual cause - the box takes the start of it and"
    echo "drops the rest, which looks exactly like this."
    echo ""
    echo "Re-run export-db-for-render.ps1, open imprint.sqlite.b64 in a real"
    echo "editor, select all, and check the paste landed whole before saving."
    echo "----------------------------------------------------------------"
    exit 1
}

if [ -s "$SECRET" ]; then
    echo "Restoring the database from the secret file..."

    # Whitespace is stripped first: a text box may wrap or re-indent what was
    # pasted, and base64 refuses the lot over a single stray newline. Real
    # corruption still fails below, where it is caught properly.
    tr -d '[:space:]' < "$SECRET" | base64 -d 2>/dev/null | gunzip > "$DB" 2>/dev/null \
        || restore_failed "it is not valid gzipped base64"

    # Decoding something is not the same as decoding a database. A truncated
    # paste can still produce bytes; this is what says whether they are one.
    head -c 15 "$DB" | grep -q "SQLite format 3" \
        || restore_failed "what came out is not a SQLite database"

    echo "Restored $(wc -c < "$DB") bytes."
else
    echo "No secret file found - starting on an empty database."
    touch "$DB"
fi

# The file AND the folder: SQLite writes its -wal and -journal beside the
# database, so a writable file in a read-only folder fails on the first save.
chown www-data:www-data "$DB"
chmod 664 "$DB"
chown www-data:www-data /var/www/html/database

# Laravel has to be looking at the file we just wrote.
#
# DB_DATABASE is set in the Render dashboard by hand, and the obvious thing to
# paste there is whatever the local .env says - which on a Windows machine is
# C:/ImprintProduction/... A path like that fails inside a Linux container as
# "database file does not exist", pointing at the one thing that is not the
# problem: the file is there, nothing is looking at it.
if [ -n "$DB_DATABASE" ] && [ "$DB_DATABASE" != "$DB" ]; then
    echo "----------------------------------------------------------------"
    echo "DB_DATABASE does not point at the database."
    echo ""
    echo "  it is set to : $DB_DATABASE"
    echo "  the file is  : $DB"
    echo ""
    echo "Set DB_DATABASE to the second path in the Render dashboard, under"
    echo "Environment. A Windows path cannot work inside a Linux container."
    echo "----------------------------------------------------------------"
    exit 1
fi

# Brings an empty database up; a no-op on a restored one already current.
php artisan migrate --force --no-interaction

php artisan config:cache
php artisan route:cache

# Render hands the port in at runtime, so it cannot be baked into the image.
sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
