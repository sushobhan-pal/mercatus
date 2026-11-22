<?php
/**
 * An exact decimal: unit prices and quantities (DATA-1, G-MONEY-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Money;

use InvalidArgumentException;

/**
 * Six decimal places, as `DECIMAL(20,6)` stores them, held as a scaled integer so nothing passes through a
 * float. Values stay below a trillion, which keeps any sum of two inside PHP's integer range.
 */
final readonly class Decimal {

	public const SCALE = 6;

	private const FACTOR = 1000000;

	/**
	 * The largest absolute value, in scaled units (just under 10^12).
	 */
	private const MAX = 999999999999999999;

	/**
	 * Creates a decimal from its scaled value.
	 *
	 * @param int $scaled The value times 10^6.
	 * @throws InvalidArgumentException When it's out of range.
	 */
	private function __construct( public int $scaled ) {
		if ( $scaled > self::MAX || $scaled < -self::MAX ) {
			throw new InvalidArgumentException( 'A decimal stays below a trillion.' );
		}
	}

	/**
	 * Parses a decimal string, such as "12", "12.5" or "-0.25", or an integer.
	 *
	 * @param string|int $value Value.
	 * @throws InvalidArgumentException When it isn't a decimal with at most six places, or is out of range.
	 */
	public static function of( string|int $value ): self {
		if ( is_int( $value ) ) {
			if ( $value > intdiv( self::MAX, self::FACTOR ) || $value < -intdiv( self::MAX, self::FACTOR ) ) {
				throw new InvalidArgumentException( 'A decimal stays below a trillion.' );
			}

			return new self( $value * self::FACTOR );
		}
		if ( 1 !== preg_match( '/^(-?)(\d{1,12})(?:\.(\d{1,6}))?$/', trim( $value ), $parts ) ) {
			throw new InvalidArgumentException( 'A decimal is digits with at most six decimal places.' );
		}
		$scaled = (int) $parts[2] * self::FACTOR + (int) str_pad( $parts[3] ?? '', self::SCALE, '0' );

		return new self( '-' === $parts[1] ? -$scaled : $scaled );
	}

	/**
	 * A decimal from its scaled value.
	 *
	 * @param int $scaled The value times 10^6.
	 */
	public static function fromScaled( int $scaled ): self {
		return new self( $scaled );
	}

	/**
	 * Zero.
	 */
	public static function zero(): self {
		return new self( 0 );
	}

	/**
	 * Whether a value parses as a decimal.
	 *
	 * @param mixed $value Value.
	 */
	public static function valid( mixed $value ): bool {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return false;
		}
		try {
			self::of( $value );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		return true;
	}

	/**
	 * The sum.
	 *
	 * @param self $other Other value.
	 */
	public function add( self $other ): self {
		return new self( $this->scaled + $other->scaled );
	}

	/**
	 * The difference.
	 *
	 * @param self $other Other value.
	 */
	public function subtract( self $other ): self {
		return new self( $this->scaled - $other->scaled );
	}

	/**
	 * Compares with another value: -1, 0 or 1.
	 *
	 * @param self $other Other value.
	 */
	public function compare( self $other ): int {
		return $this->scaled <=> $other->scaled;
	}

	/**
	 * Whether it equals another value.
	 *
	 * @param self $other Other value.
	 */
	public function equals( self $other ): bool {
		return $this->scaled === $other->scaled;
	}

	/**
	 * Whether it's below zero.
	 */
	public function isNegative(): bool {
		return $this->scaled < 0;
	}

	/**
	 * Whether it's zero.
	 */
	public function isZero(): bool {
		return 0 === $this->scaled;
	}

	/**
	 * Whether it has no fractional part.
	 */
	public function isWhole(): bool {
		return 0 === $this->scaled % self::FACTOR;
	}

	/**
	 * The whole part, for an API that counts in whole units, such as WooCommerce's stock quantity; Free
	 * sells by the piece (CAT-1).
	 */
	public function toInt(): int {
		return intdiv( $this->scaled, self::FACTOR );
	}

	/**
	 * The shortest exact form, such as "12.5" or "10", for the API and WooCommerce.
	 */
	public function toString(): string {
		$fixed = $this->toDatabase();

		return rtrim( rtrim( $fixed, '0' ), '.' );
	}

	/**
	 * The form `DECIMAL(20,6)` stores, such as "12.500000".
	 */
	public function toDatabase(): string {
		$absolute = abs( $this->scaled );

		return ( $this->scaled < 0 ? '-' : '' ) . intdiv( $absolute, self::FACTOR ) . '.' . str_pad( (string) ( $absolute % self::FACTOR ), self::SCALE, '0', STR_PAD_LEFT );
	}
}
