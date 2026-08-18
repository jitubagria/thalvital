#!/usr/bin/env bash
# ThalVital database backup — timestamped, compressed mysqldump with a size sanity
# check and retention pruning. Intended for cron on the production VPS, and usable
# manually before any migration.
#
#   Usage: backup-db.sh [APP_DIR] [DEST_DIR] [RETAIN_DAYS]
#   Defaults: APP_DIR=/var/www/thalvital/current/public
#             DEST_DIR=/var/www/thalvital/shared/backups   RETAIN_DAYS=14
#
# Reads DB credentials from the app's config.php via php-cli (the AADHAAR_SALT is never
# read or printed). The DB password is passed through MYSQL_PWD (not the command line),
# so it does not appear in `ps`.
set -euo pipefail

APP_DIR="${1:-/var/www/thalvital/current/public}"
DEST="${2:-/var/www/thalvital/shared/backups}"
RETAIN_DAYS="${3:-14}"
CONFIG="$APP_DIR/config.php"
PHP="${PHP_BIN:-php}"

[ -f "$CONFIG" ] || { echo "config.php not found at $CONFIG" >&2; exit 1; }

# Pull only the DB connection constants (never the salt).
creds="$("$PHP" -r 'require $argv[1]; printf("%s\n%s\n%s\n%s\n", DB_HOST, DB_NAME, DB_USER, DB_PASS);' "$CONFIG")"
DBH="$(printf '%s' "$creds" | sed -n 1p)"
DBN="$(printf '%s' "$creds" | sed -n 2p)"
DBU="$(printf '%s' "$creds" | sed -n 3p)"
DBP="$(printf '%s' "$creds" | sed -n 4p)"

mkdir -p "$DEST"
TS="$(date +%Y%m%d-%H%M%S)"
OUT="$DEST/thalvital-$TS.sql.gz"

MYSQL_PWD="$DBP" mysqldump --host="$DBH" --user="$DBU" \
  --single-transaction --routines --triggers --no-tablespaces --default-character-set=utf8mb4 \
  "$DBN" | gzip > "$OUT"

SIZE="$(wc -c < "$OUT")"
if [ "$SIZE" -lt 1000 ]; then
  echo "BACKUP FAILED: $OUT is only $SIZE bytes (expected a real dump)" >&2
  exit 2
fi
echo "backup ok: $OUT ($SIZE bytes)"

# Prune backups older than RETAIN_DAYS (independent encrypted/off-box retention is separate).
find "$DEST" -maxdepth 1 -name 'thalvital-*.sql.gz' -mtime +"$RETAIN_DAYS" -delete 2>/dev/null || true
