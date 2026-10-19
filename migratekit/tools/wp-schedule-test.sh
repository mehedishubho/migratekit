#!/bin/sh
set -e

echo "Waiting for WordPress files and wp-config.php..."
i=0
while [ ! -f /var/www/html/wp-config.php ] || [ ! -f /var/www/html/wp-settings.php ]; do
	i=$((i + 1))
	if [ "$i" -gt 90 ]; then
		echo "WordPress did not become ready in time." >&2
		exit 1
	fi
	sleep 1
done

if ! wp core is-installed --allow-root >/dev/null 2>&1; then
	echo "Installing WordPress..."
	i=0
	while ! wp core install \
		--url=http://localhost:8099 \
		--title=MigrateKitTest \
		--admin_user=admin \
		--admin_password=admin123 \
		--admin_email=admin@example.com \
		--skip-email \
		--allow-root; do
		i=$((i + 1))
		if [ "$i" -gt 60 ]; then
			echo "WordPress install failed." >&2
			exit 1
		fi
		sleep 1
	done
fi

wp plugin activate migratekit --allow-root
wp eval-file /schedule-smoke.php --allow-root
