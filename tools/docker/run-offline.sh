#!/bin/sh
# Run every offline test suite with any stock PHP binary.
cd "$(dirname "$0")/../../tests" || exit 1

status=0
for f in run.php run-database.php run-pipeline.php run-import.php run-storage.php run-reset.php run-download.php run-cloud.php run-multisite.php run-parity.php; do
	echo "===== $f ====="
	php -d memory_limit=512M "$f" || status=1
done

exit $status
