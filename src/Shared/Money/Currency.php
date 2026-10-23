<?php
/**
 * ISO 4217 currency code (DATA-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Money;

use InvalidArgumentException;

/**
 * A three-letter ISO 4217 currency code. Decimal places per currency are the ledger's concern (MON-11).
 */
final readonly class Currency {

	/**
	 * Creates a currency from its code.
	 *
	 * @param string $code Upper-case ISO 4217 code, for example INR.
	 * @throws InvalidArgumentException When the code isn't three upper-case letters.
	 */
	public function __construct( public string $code ) {
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
			throw new InvalidArgumentException( 'A currency code is three upper-case letters (ISO 4217).' );
		}
	}

	/**
	 * Whether two currencies are the same.
	 *
	 * @param self $other Currency to compare with.
	 */
	public function equals( self $other ): bool {
		return $this->code === $other->code;
	}
}
