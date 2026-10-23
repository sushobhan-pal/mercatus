<?php
/**
 * An address as tax rates match it (TAX-6, TAX-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

use InvalidArgumentException;

/**
 * The parts of an address that WooCommerce-shaped tax rates match on.
 */
final readonly class TaxAddress {

	/**
	 * Creates a tax address.
	 *
	 * @param string $country  ISO 3166-1 alpha-2 code, upper case.
	 * @param string $state    State or region code, empty when unknown.
	 * @param string $postcode Postcode, empty when unknown.
	 * @param string $city     City, empty when unknown.
	 * @throws InvalidArgumentException When the country isn't two upper-case letters.
	 */
	public function __construct(
		public string $country,
		public string $state = '',
		public string $postcode = '',
		public string $city = '',
	) {
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			throw new InvalidArgumentException( 'A country is a two-letter upper-case ISO 3166-1 code.' );
		}
	}
}
