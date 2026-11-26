#!/bin/sh
set -e
for f in tests/run.php tests/run-storage.php tests/run-database.php tests/run-pipeline.php tests/run-import.php tests/run-reset.php tests/run-download.php tests/run-cloud.php tests/run-multisite.php tests/run-parity.php; do
	echo "== $f"
	php "$f" 2>&1 | tail -1
done
echo "== tests/run-parity.php with link() disabled, as on some hosts"
php -d disable_functions=link tests/run-parity.php 2>&1 | tail -1
echo ALL-DONE
