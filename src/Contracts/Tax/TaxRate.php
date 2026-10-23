<?php
/**
 * A tax rate in WooCommerce's shape (TAX-5, TAX-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

use InvalidArgumentException;

/**
 * One rate for WooCommerce to apply to a line. Where vendor rate IDs come from is settled by OPEN-1.
 */
final readonly class TaxRate {

	/**
	 * Creates a rate.
	 *
	 * @param int    $rateId   Integer rate ID that WooCommerce stores on order tax lines.
	 * @param string $label    Label shown on orders, for example "CGST".
	 * @param string $percent  Exact decimal string, zero or more (G-MONEY-2).
	 * @param int    $priority WooCommerce rate priority, 1 or more.
	 * @param bool   $compound Whether the rate applies on top of other taxes.
	 * @param bool   $shipping Whether the rate also applies to shipping.
	 * @throws InvalidArgumentException When the ID, label, percent or priority is invalid.
	 */
	public function __construct(
		public int $rateId,
		public string $label,
		public string $percent,
		public int $priority = 1,
		public bool $compound = false,
		public bool $shipping = true,
	) {
		if ( $rateId < 1 || '' === $label || $priority < 1 ) {
			throw new InvalidArgumentException( 'A tax rate needs a positive ID, a label and a priority of 1 or more.' );
		}
		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $percent ) ) {
			throw new InvalidArgumentException( 'A tax rate percent is a non-negative decimal string.' );
		}
	}
}
