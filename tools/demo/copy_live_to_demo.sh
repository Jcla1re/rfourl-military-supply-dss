#!/usr/bin/env bash
#
# Copies the LIVE database into the DEMO database (run as root on the server).
#
#   sudo tools/demo/copy_live_to_demo.sh <live_db> <demo_db>
#
# Safety:
#   - the demo database name must contain "demo" and differ from the live name;
#   - the live database is only READ (mysqldump --single-transaction);
#   - DEFINER clauses are stripped so routines/triggers/views load under any account.
#
# After the copy, run on the demo site:  php spark migrate && php spark demo:setup && php spark demo:snapshot
# (migrate is harmless if live was already migrated: it only adds what is missing).
#
# Set MYSQL / MYSQLDUMP to use other binaries. Uses the root login of the local server
# (socket auth); set MYSQL_OPTS to add options such as --defaults-file=/root/.my.cnf.

set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 <live_db> <demo_db>" >&2
    exit 2
fi

LIVE_DB="$1"
DEMO_DB="$2"
MYSQL="${MYSQL:-mysql}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"
# shellcheck disable=SC2206
OPTS=(${MYSQL_OPTS:-})

if [ "$(id -u)" -ne 0 ]; then
    echo "Refusing to run: this script must be run as root (sudo)." >&2
    exit 1
fi

if ! [[ "$LIVE_DB" =~ ^[A-Za-z0-9_]+$ && "$DEMO_DB" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Refusing to run: database names may only contain letters, digits and underscores." >&2
    exit 1
fi

if [ "$LIVE_DB" = "$DEMO_DB" ]; then
    echo "Refusing to run: the demo database must differ from the live database." >&2
    exit 1
fi

if [[ "${DEMO_DB,,}" != *demo* ]]; then
    echo "Refusing to run: the target database name \"$DEMO_DB\" does not contain \"demo\"." >&2
    exit 1
fi

counts() {
    # $1 = database name. Prints "products=N receipts=N".
    local p r
    p=$("$MYSQL" "${OPTS[@]}" -N -B -e "SELECT COUNT(*) FROM \`$1\`.products")
    r=$("$MYSQL" "${OPTS[@]}" -N -B -e "SELECT COUNT(*) FROM \`$1\`.sales_transaction")
    echo "products=$p receipts=$r"
}

echo "Copying $LIVE_DB -> $DEMO_DB (the live database is only read)..."

"$MYSQL" "${OPTS[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DEMO_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

"$MYSQLDUMP" "${OPTS[@]}" --single-transaction --routines --triggers --no-tablespaces "$LIVE_DB" \
    | sed -E 's/\/\*![0-9]* DEFINER=[^*]*\*\/ ?//g; s/ ?DEFINER=`[^`]*`@`[^`]*`//g' \
    | "$MYSQL" "${OPTS[@]}" "$DEMO_DB"

echo
echo "Row counts (they should match):"
echo "  live  ($LIVE_DB): $(counts "$LIVE_DB")"
echo "  demo  ($DEMO_DB): $(counts "$DEMO_DB")"
echo
echo "Next, on the demo site: php spark migrate && php spark demo:setup && php spark demo:snapshot"
