<?php
/**
 * The stack Mercatus needs (PLAT-1, KER-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Checked before anything loads. WordPress itself enforces the PHP version from the plugin header.
 */
final class Requirements {

	public const WORDPRESS   = '6.6';
	public const WOOCOMMERCE = '9.2';

	/**
	 * Messages for each unmet requirement; empty when Mercatus can run.
	 *
	 * @return list<string>
	 */
	public static function unmet(): array {
		$unmet = array();
		if ( version_compare( (string) get_bloginfo( 'version' ), self::WORDPRESS, '<' ) ) {
			$unmet[] = __( 'Mercatus needs WordPress 6.6 or later.', 'mercatus' );
		}
		if ( ! did_action( 'woocommerce_loaded' ) || version_compare( WC()->version, self::WOOCOMMERCE, '<' ) ) {
			$unmet[] = __( 'Mercatus needs WooCommerce 9.2 or later, active.', 'mercatus' );
		} elseif ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$unmet[] = __( 'Mercatus needs WooCommerce High-Performance Order Storage switched on.', 'mercatus' );
		}

		return $unmet;
	}
}
