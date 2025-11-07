#!/usr/bin/env bash
set -euo pipefail

# Config via env (with sane defaults)
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-appuser}"
DB_PASS="${DB_PASS:-apppass}"
DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-60}"

echo "⏳ Waiting for MySQL at ${DB_HOST}:${DB_PORT} (timeout: ${DB_WAIT_TIMEOUT}s)..."
deadline=$((SECONDS + DB_WAIT_TIMEOUT))
while ! mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --silent; do
  if (( SECONDS >= deadline )); then
    echo "⚠️  MySQL not ready after ${DB_WAIT_TIMEOUT}s. Continuing startup anyway."
    break
  fi
  sleep 2
done

# Try schema creation, but don't block Apache if it fails
if [ -f /var/www/html/createTables.php ]; then
  echo "✅ Attempting to run createTables.php..."
  if ! php /var/www/html/createTables.php; then
    echo "⚠️  createTables.php failed (will not block Apache). Check logs."
  fi
else
  echo "ℹ️  /var/www/html/createTables.php not found. Skipping."
fi

echo "🚀 Starting Apache..."
exec apache2-foreground
