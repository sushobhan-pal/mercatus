# Sourced by the test runners inside the wordpress service: installs the object-cache drop-in that CACHE_MODE
# selects (none, redis or memcached), and Composer dependencies when they're missing.
content_dir=/usr/src/wordpress/wp-content
rm -f "$content_dir/object-cache.php" "$content_dir/wp-memcached.php"

if [ "${CACHE_MODE:-none}" = "memcached" ] && ! php -r 'exit( extension_loaded( "memcache" ) ? 0 : 1 );'; then
	php_version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
	echo "The memcached profile needs the PECL memcache extension, which doesn't build on PHP ${php_version} yet." >&2
	echo "Use the redis or none cache profile with this image, or a PHP version the extension supports." >&2
	exit 1
fi

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
