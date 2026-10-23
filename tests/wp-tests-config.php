<?php
/**
 * WordPress test-suite configuration for the Docker test environment (TEST-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

/**
 * Reads an environment variable, falling back to a default when it's unset or empty.
 *
 * @param string $name     Variable name.
 * @param string $fallback Value used when the variable is unset or empty.
 */
function mercatus_tests_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $fallback : $value;
}

define( 'ABSPATH', '/usr/src/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', mercatus_tests_env( 'DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', mercatus_tests_env( 'DB_USER', 'wp' ) );
define( 'DB_PASSWORD', mercatus_tests_env( 'DB_PASSWORD', 'wp' ) );
define( 'DB_HOST', mercatus_tests_env( 'DB_HOST', 'db' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the test suite reads this global.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Mercatus Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// The suite runs as root while WordPress's files belong to www-data; without this, WordPress falls back to
// asking for FTP credentials whenever something (such as WooCommerce's log directory setup) uses WP_Filesystem.
define( 'FS_METHOD', 'direct' );

// Settings for the object-cache drop-ins; only the one the runner installed reads its own. PHPUnit includes this
// file from inside a function, so the wp-memcached server list must be set as a real global.
define( 'WP_REDIS_HOST', 'redis' );
$GLOBALS['memcached_servers'] = array( 'memcached:11211' );
