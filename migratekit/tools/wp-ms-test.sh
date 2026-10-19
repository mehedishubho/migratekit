#!/bin/sh
# Container-side driver for the MigrateKit multisite smoke.
#
# Usage: /smoke/wp-ms-test.sh <network-source|network-target|plain>
#
# network-source: installs the source network, seeds content, exports a whole
#                 network archive and a subsite archive into /shared.
# network-target: installs a fresh network, imports the network archive and
#                 verifies the outcome, then checks the subsite guard.
# plain:          installs a standalone site, checks the network guard,
#                 imports the subsite archive and verifies the outcome.

set -e

MODE="${1:-}"

fail() {
	echo "FAIL  - $1" >&2
	exit 1
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

install_network() {
	# $1 = url, $2 = title
	if ! wp core is-installed --url="$1" --allow-root >/dev/null 2>&1; then
		echo "Installing $1..."
		wp core install \
			--url="$1" \
			--title="$2" \
			--admin_user=admin \
			--admin_password=mk-admin-1 \
			--admin_email=admin@example.test \
			--skip-email \
			--allow-root
	fi

	wp core multisite-install \
		--url="$1" \
		--title="$2" \
		--admin_user=admin \
		--admin_email=admin@example.test \
		--allow-root

	wp super-admin add admin --allow-root >/dev/null 2>&1 || true
}

install_plain() {
	if ! wp core is-installed --url="$1" --allow-root >/dev/null 2>&1; then
		echo "Installing $1..."
		wp core install \
			--url="$1" \
			--title=StandAlone \
			--admin_user=admin \
			--admin_password=mk-admin-1 \
			--admin_email=admin@example.test \
			--skip-email \
			--allow-root
	fi

	wp plugin activate migratekit --allow-root
}

latest_archive() {
	ls -t /var/www/html/wp-content/migratekit-backups/*.wpress 2>/dev/null | head -1
}

wait_ready

case "$MODE" in
	network-source)
		install_network 'http://wp-source' 'Source Network'
		wp site create --slug=site2 --url=http://wp-source --allow-root >/dev/null 2>&1 || true
		wp site create --slug=site3 --url=http://wp-source --allow-root >/dev/null 2>&1 || true
		wp plugin activate migratekit --network --allow-root
		wp eval-file /smoke/wp-ms-seed.php --url=http://wp-source --allow-root
		wp migratekit export --network=whole --url=http://wp-source --allow-root || fail "network export failed"
		network_archive="$(latest_archive)"
		[ -n "$network_archive" ] || fail "archive not found under migratekit-backups"
		cp -f "$network_archive" /shared/network.wpress
		wp migratekit export --network=subsite --url=http://wp-source/site2 --allow-root || fail "subsite export failed"
		subsite_archive="$(latest_archive)"
		[ -n "$subsite_archive" ] || fail "subsite archive not found"
		cp -f "$subsite_archive" /shared/subsite.wpress
		echo "source side complete: network.wpress + subsite.wpress"
		;;
	network-target)
		install_network 'http://wp-target' 'Target Network'
		wp plugin activate migratekit --network --allow-root
		wp migratekit import /shared/network.wpress --url=http://wp-target --allow-root || fail "network import failed"
		wp eval-file /smoke/wp-ms-verify.php network-target --url=http://wp-target --allow-root

		set +e
		guard_out="$(wp migratekit import /shared/subsite.wpress --url=http://wp-target --allow-root 2>&1)"
		guard_code=$?
		set -e

		if [ "$guard_code" -eq 0 ]; then
			fail "subsite archive import into a network should have been rejected"
		fi

		case "$guard_out" in
			*standalone*) echo "  ok  - subsite archive refused on networks" ;;
			*) fail "guard message unexpected: $guard_out" ;;
		esac

		echo "target side complete"
		;;
	plain)
		install_plain 'http://wp-plain'
		wp plugin activate migratekit --allow-root

		set +e
		guard_out="$(wp migratekit import /shared/network.wpress --url=http://wp-plain --allow-root 2>&1)"
		guard_code=$?
		set -e

		if [ "$guard_code" -eq 0 ]; then
			fail "network archive import into a plain site should have been rejected"
		fi

		case "$guard_out" in
			*multisite\ network*) echo "  ok  - network archive refused on a standalone site" ;;
			*) fail "guard message unexpected: $guard_out" ;;
		esac

		wp migratekit import /shared/subsite.wpress --url=http://wp-plain --allow-root || fail "subsite import into the plain site failed"
		wp eval-file /smoke/wp-ms-verify.php plain --url=http://wp-plain --allow-root
		echo "plain side complete"
		;;
	*)
		fail "usage: wp-ms-test.sh <network-source|network-target|plain>"
		;;
esac
