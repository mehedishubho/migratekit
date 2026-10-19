#!/bin/sh
# Container-side driver for the MigrateKit parity smoke.
#
# Usage: /smoke/wp-parity-test.sh
#
# Exports with the full free-tier+paid flag set (compression, encryption,
# inactive-plugin filter, find/replace pair, table exclusion), verifies the
# archive contents via wp-parity-inspect.php, then imports the encrypted
# archive back and verifies the outcome. Single-site mode.

set -e

fail() {
	echo "FAIL  - $1" >&2
	exit 1
}

must() {
	# $1 = label, rest = pipeline to evaluate
	label="$1"
	shift
	if "$@" >/dev/null 2>&1; then
		echo "  ok  - $label"
	else
		fail "$label"
	fi
}

wait_ready() {
	i=0
	while [ ! -f /var/www/html/wp-settings.php ]; do
		i=$((i + 1))
		if [ "$i" -gt 90 ]; then
			fail "wordpress files never appeared"
		fi
		sleep 1
	done
}

cd /var/www/html
wait_ready

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
	echo "Installing WordPress..."
	wp core install \
		--url="http://wp-source" \
		--title="MigrateKit parity smoke" \
		--admin_user="admin" \
		--admin_password="mk-smoke-1" \
		--admin_email="admin@mk-smoke.test" \
		--skip-email \
		--allow-root
fi

if ! wp plugin is-active migratekit --allow-root >/dev/null 2>&1; then
	echo "Activating MigrateKit..."
	wp plugin activate migratekit --allow-root
fi

echo "== Seeding =="

wp option update blogname "Canon archive for mk-canon.test" --allow-root
wp db query "CREATE TABLE IF NOT EXISTS wp_mk_demo (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(64))" --allow-root
wp db query "INSERT INTO wp_mk_demo (note) VALUES ('untouched by migration')" --allow-root

echo "  ok  - blogname and demo table seeded"

echo "== Export with the full option set =="

OUT=$(wp migratekit export \
	--compression=gzip \
	--password=mk-smoke-1 \
	--skip-inactive-plugins \
	--skip-inactive-themes \
	--skip-post-revisions \
	--skip-spam-comments \
	--replace-old=mk-canon.test \
	--replace-new=mk-copy.test \
	--exclude-tables=wp_mk_demo \
	--allow-root)

NAME=$(echo "$OUT" | sed -n 's/^Success: Backup created\. Archive: //p')

if [ -z "$NAME" ]; then
	echo "$OUT" >&2
	fail "export did not report the archive name"
fi

echo "  ok  - export completed: $NAME"

BACKUPS=/var/www/html/wp-content/migratekit-backups
ARCHIVE="$BACKUPS/$NAME"

if ! [ -s "$ARCHIVE" ]; then
	fail "archive missing on disk: $ARCHIVE"
fi

wp migratekit label "$NAME" "Smoke label" --allow-root
echo "  ok  - label command accepted"

must "backups listing shows the label" sh -c "wp migratekit backups --allow-root | grep 'Smoke label'"

must "files command lists package.json" sh -c "wp migratekit files \"$NAME\" --password=mk-smoke-1 --allow-root | grep package.json"
must "files command lists wp-content paths" sh -c "wp migratekit files \"$NAME\" --password=mk-smoke-1 --allow-root | grep themes/"

echo "== Archive-level verification =="

wp eval-file /smoke/wp-parity-inspect.php "$NAME" mk-canon.test mk-copy.test mk-smoke-1 wp_mk_demo --allow-root | tee /tmp/parity-inspect.log

if grep -q '^FAIL' /tmp/parity-inspect.log; then
	fail "archive inspection reported failures"
fi

echo "== Import back (encrypted, password protected) =="

wp migratekit import "$ARCHIVE" --password=mk-smoke-1 --allow-root >/tmp/parity-import.log
echo "  ok  - import job driven to completion"

BLOGNAME=$(wp option get blogname --allow-root)

if [ "$BLOGNAME" = "Canon archive for mk-copy.test" ]; then
	echo "  ok  - find/replace pair landed in the restored database"
else
	fail "blogname after import is '$BLOGNAME', expected the replaced value"
fi

must "excluded table survived the import untouched" sh -c "wp db query 'SELECT note FROM wp_mk_demo' --allow-root | grep 'untouched by migration'"

wp eval "var_export(\MigrateKit\Support\Maintenance::is_on());" --allow-root > /tmp/parity-maint.log
if [ "$(cat /tmp/parity-maint.log)" = "false" ]; then
	echo "  ok  - maintenance flag cleared when the import finished"
else
	fail "maintenance flag stuck on: $(cat /tmp/parity-maint.log)"
fi

must "archive survived its own import (delete cycle intact)" sh -c "wp migratekit backups --allow-root | grep \"$NAME\""

echo "PARITY-SMOKE-OK"
