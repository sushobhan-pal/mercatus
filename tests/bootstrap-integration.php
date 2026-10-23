<?php
/**
 * Bootstrap for the integration suite: loads the WordPress test library, WooCommerce with HPOS, and Mercatus.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;

$mercatus_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $mercatus_tests_dir || '' === $mercatus_tests_dir ) {
	$mercatus_tests_dir = '/opt/wp-tests-lib';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $mercatus_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		// Mercatus checks HPOS when it boots on plugins_loaded (PLAT-1), before WooCommerce is installed below.
		update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, 'yes' );
		require_once dirname( __DIR__ ) . '/mercatus.php';
	}
);

// Installs WooCommerce into the test database and switches order storage to HPOS (PLAT-1), the way
// WooCommerce's own test suite does; a fresh install in the test suite doesn't enable it by itself.
tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();

		$synchronizer = wc_get_container()->get( DataSynchronizer::class );
		if ( ! $synchronizer->check_orders_table_exists() ) {
			$synchronizer->create_database_tables();
		}
		update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, 'yes' );
	}
);

require_once $mercatus_tests_dir . '/includes/bootstrap.php';
