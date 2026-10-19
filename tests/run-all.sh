#!/bin/sh
set -e
for f in tests/run.php tests/run-storage.php tests/run-database.php tests/run-pipeline.php tests/run-import.php tests/run-reset.php tests/run-cloud.php tests/run-multisite.php tests/run-parity.php; do
	echo "== $f"
	php "$f" 2>&1 | tail -1
done
echo ALL-DONE
