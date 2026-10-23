<?php
/**
 * A monetary amount in minor units (DATA-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Money;

/**
 * An amount in integer minor units, such as paise or cents, with its currency. Never a float (G-MONEY-1).
 * Arithmetic and allocation arrive with the ledger (MON-8, MON-11).
 */
final readonly class Money {

	/**
	 * Creates an amount.
	 *
	 * @param int      $minor    Amount in minor units; negative for debits and refunds.
	 * @param Currency $currency Currency of the amount.
	 */
	public function __construct( public int $minor, public Currency $currency ) {}

	/**
	 * Creates an amount from minor units and a currency code.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO 4217 currency code.
	 */
	public static function of( int $minor, string $currency ): self {
		return new self( $minor, new Currency( $currency ) );
	}

	/**
	 * Whether two amounts have the same value and currency.
	 *
	 * @param self $other Amount to compare with.
	 */
	public function equals( self $other ): bool {
		return $this->minor === $other->minor && $this->currency->equals( $other->currency );
	}

	/**
	 * Whether the amount is greater than zero.
	 */
	public function isPositive(): bool {
		return $this->minor > 0;
	}
}
