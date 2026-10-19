#!/bin/sh
set -e

NAME=$(wp migratekit backups --allow-root 2>/dev/null | tail -n +2 | head -1 | sed 's/[[:space:]].*//')

if [ -z "$NAME" ]; then
	echo "Creating an export for the CLI round trip..."
	wp migratekit export --allow-root >/dev/null
	NAME=$(wp migratekit backups --allow-root 2>/dev/null | tail -n +2 | head -1 | sed 's/[[:space:]].*//')
fi

echo "using local backup: $NAME"

wp migratekit cloud push "$NAME" --allow-root
wp migratekit cloud files --allow-root

echo "Deleting the local copy, then pulling it back..."
wp eval "\$p = WP_CONTENT_DIR . '/migratekit-backups/$NAME'; is_file( \$p ) && unlink( \$p );" --allow-root

wp migratekit cloud pull "$NAME" --allow-root
wp migratekit cloud files --allow-root

wp eval 'is_file( WP_CONTENT_DIR . "/migratekit-backups/'"$NAME"'" ) ? print( "local file restored\n" ) : print( "local file MISSING\n" );' --allow-root
