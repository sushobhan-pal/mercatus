<?php
/**
 * A tax-rate resolver's answer (TAX-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

use InvalidArgumentException;

/**
 * Taxes per line key: rates for WooCommerce to calculate with, or ready-made amounts, never both for one
 * line. A line in neither map is untaxed (TAX-9).
 */
final readonly class TaxResolution {

	/**
	 * Creates a resolution.
	 *
	 * @param array<string, list<TaxRate>>   $rates   Rates by line key.
	 * @param array<string, list<TaxAmount>> $amounts Amounts by line key.
	 * @throws InvalidArgumentException When a line has both rates and amounts.
	 */
	public function __construct( public array $rates = array(), public array $amounts = array() ) {
		if ( array() !== array_intersect_key( $rates, $amounts ) ) {
			throw new InvalidArgumentException( 'A line has either rates or amounts, not both.' );
		}
	}

	/**
	 * Rates for a line; empty when it has none.
	 *
	 * @param string $key Line key.
	 * @return list<TaxRate>
	 */
	public function ratesFor( string $key ): array {
		return $this->rates[ $key ] ?? array();
	}

	/**
	 * Amounts for a line; empty when it has none.
	 *
	 * @param string $key Line key.
	 * @return list<TaxAmount>
	 */
	public function amountsFor( string $key ): array {
		return $this->amounts[ $key ] ?? array();
	}
}
