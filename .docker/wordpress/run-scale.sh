#!/usr/bin/env bash
# Runs inside the wordpress service for bin/scale.sh (PLAT-10, KER-12) against a real site, not the WordPress
# test suite, using the table prefix wpscale_:
#   seed               installs the site, activates WooCommerce and Mercatus, and runs wp mercatus seed:scale
#   test [phpunit...]  brings a restored site up to date and runs tests/Scale
set -euo pipefail

# shellcheck source=SCRIPTDIR/prepare-environment.sh
source "$(dirname "$0")/prepare-environment.sh"

site=/usr/src/wordpress
wp() {
	command wp --path="$site" "$@"
}

if [ ! -f "$site/wp-config.php" ]; then
	wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" \
		--dbprefix=wpscale_ --skip-check --skip-salts --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'FS_METHOD', 'direct' );
define( 'WP_REDIS_HOST', 'redis' );
$GLOBALS['memcached_servers'] = array( 'memcached:11211' );
PHP
fi

case "${1:-}" in
	seed)
		# Nobody signs in to this site, so the administrator gets a random password that is never shown.
		wp core install --url=http://scale.example --title='Mercatus scale' --admin_user=scale-admin \
			--admin_email=scale-admin@example.org --admin_password="$(head -c 24 /dev/urandom | base64)" --skip-email
		wp plugin activate woocommerce
		# Mercatus needs HPOS (PLAT-1), which a WooCommerce install from WP-CLI leaves off.
		wp wc hpos enable
		wp plugin activate mercatus
		wp mercatus seed:scale ${SCALE_FRACTION:+"--scale=$SCALE_FRACTION"}
		;;
	test)
		shift
		wp core update-db
		wp wc update
		wp mercatus migrate
		exec vendor/bin/phpunit -c phpunit-scale.xml.dist "$@"
		;;
	*)
		echo "Usage: $0 seed | test [phpunit args...]" >&2
		exit 1
		;;
esac
