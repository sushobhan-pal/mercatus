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

// Creates Mercatus's tables the way plugin activation does (KER-5): after WooCommerce has loaded, before the
// site's kernel boots on plugins_loaded, so the kernel starts with its tables in place.
tests_add_filter(
	'plugins_loaded',
	static function (): void {
		\Mercatus\Core\Kernel\Kernel::activate();
	},
	5
);

// Each test runs inside the test suite's transaction, so the site kernel's transactions use savepoints there
// (KER-21); a real COMMIT would end the test's transaction and leak its rows into later tests. The factory is
// registered while the site kernel starts, before any module hook can build a service that holds the runner.
tests_add_filter(
	'mercatus_register_modules',
	static function (): void {
		static $done = false;
		$kernel      = \Mercatus\Core\Kernel\Kernel::instance();
		if ( $done || null === $kernel ) {
			return;
		}
		$done = true;
		$kernel->container()->factory(
			\Mercatus\Core\Infrastructure\Database\WpdbTransactionRunner::class,
			static function ( \Mercatus\Core\Kernel\Container $services ): \Mercatus\Core\Infrastructure\Database\WpdbTransactionRunner {
				global $wpdb;

				return new \Mercatus\Core\Infrastructure\Database\WpdbTransactionRunner(
					$wpdb,
					$services->get( \Mercatus\Core\Infrastructure\Database\UnitOfWork::class ),
					$services->get( \Mercatus\Core\Infrastructure\Outbox\OutboxStore::class ),
					$services->get( \Mercatus\Core\Infrastructure\Audit\AuditStore::class ),
					$services->get( \Mercatus\Core\Infrastructure\Outbox\OutboxProcessor::class ),
					$services->get( \Mercatus\Core\Shared\Clock\Clock::class ),
					true
				);
			}
		);
	},
	1
);

require_once $mercatus_tests_dir . '/includes/bootstrap.php';
