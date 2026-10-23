#!/usr/bin/env bash
# Runs inside the wordpress service: installs the drop-in CACHE_MODE selects, installs Composer
# dependencies when they're missing, then runs the integration suite.
set -euo pipefail

content_dir=/usr/src/wordpress/wp-content
rm -f "$content_dir/object-cache.php" "$content_dir/wp-memcached.php"

case "${CACHE_MODE:-none}" in
	redis | memcached)
		cp "/opt/mercatus-dropins/${CACHE_MODE}/"*.php "$content_dir/"
		;;
	none) ;;
	*)
		echo "Unknown CACHE_MODE '${CACHE_MODE}'; use none, redis or memcached." >&2
		exit 1
		;;
esac

if [ ! -f vendor/autoload.php ]; then
	composer install --no-interaction --no-progress
fi

exec vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"
