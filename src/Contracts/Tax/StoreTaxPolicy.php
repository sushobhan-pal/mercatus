<?php
/**
 * Whether a vendor's own tax rates apply (TAX-1, TAX-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

/**
 * The single decision point for store tax. Core's implementation reads the "Enable store tax" setting and
 * passes the answer through the mercatus_store_tax_enabled filter, so membership or vendor-level settings
 * can be added later without touching the tax flow.
 */
interface StoreTaxPolicy {

	/**
	 * Whether the vendor's own rates apply instead of WooCommerce's.
	 *
	 * @param int $vendor_id Vendor ID.
	 */
	public function isEnabledFor( int $vendor_id ): bool;
}
