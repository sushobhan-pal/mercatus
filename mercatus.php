<?php
/**
 * Plugin Name:          Mercatus
 * Plugin URI:           https://github.com/sushobhan-pal/mercatus
 * Description:          API-first multivendor marketplace for WooCommerce.
 * Version:              0.1.0-dev
 * Requires at least:    6.6
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               sushobhan-pal
 * Author URI:           https://github.com/sushobhan-pal
 * License:              GPL-3.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          mercatus
 * Domain Path:          /languages
 * WC requires at least: 9.2
 * WC tested up to:      11.2
 *
 * @package Mercatus
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'MERCATUS_VERSION', '0.1.0-dev' );
define( 'MERCATUS_PLUGIN_FILE', __FILE__ );

$mercatus_autoloader = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $mercatus_autoloader ) ) {
	require_once $mercatus_autoloader;
}
unset( $mercatus_autoloader );

// HPOS is mandatory (PLAT-1).
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MERCATUS_PLUGIN_FILE, true );
		}
	}
);
