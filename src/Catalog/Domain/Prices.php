<?php
/**
 * A regular price with an optional scheduled sale (CTL-22).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

use DateTimeImmutable;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Money\Decimal;

/**
 * Prices are exact decimals (DATA-1). A draft may have no regular price yet; a sale price needs one, stays
 * below it, and its end comes after its start. A sale with no dates runs until the vendor removes it.
 */
final readonly class Prices {

	/**
	 * Creates the prices.
	 *
	 * @param Decimal|null           $regular      Regular price.
	 * @param Decimal|null           $sale         Sale price.
	 * @param DateTimeImmutable|null $saleStartsAt When the sale starts, UTC; null for at once.
	 * @param DateTimeImmutable|null $saleEndsAt   When the sale ends, UTC; null for never.
	 * @throws DomainError When they don't fit together (mercatus_product_invalid).
	 */
	public function __construct(
		public ?Decimal $regular,
		public ?Decimal $sale = null,
		public ?DateTimeImmutable $saleStartsAt = null,
		public ?DateTimeImmutable $saleEndsAt = null,
	) {
		if ( null !== $regular && $regular->isNegative() ) {
			throw self::invalid( 'regular_price' );
		}
		if ( null !== $sale && ( null === $regular || $sale->isNegative() || $sale->compare( $regular ) >= 0 ) ) {
			throw self::invalid( 'sale_price' );
		}
		if ( null === $sale && ( null !== $saleStartsAt || null !== $saleEndsAt ) ) {
			throw self::invalid( 'sale_price' );
		}
		if ( null !== $saleStartsAt && null !== $saleEndsAt && $saleEndsAt <= $saleStartsAt ) {
			throw self::invalid( 'sale_ends_at' );
		}
	}

	/**
	 * No prices yet.
	 */
	public static function none(): self {
		return new self( null );
	}

	/**
	 * Whether the sale runs at a time.
	 *
	 * @param DateTimeImmutable $now Time.
	 */
	public function onSale( DateTimeImmutable $now ): bool {
		return null !== $this->sale
			&& ( null === $this->saleStartsAt || $this->saleStartsAt <= $now )
			&& ( null === $this->saleEndsAt || $now < $this->saleEndsAt );
	}

	/**
	 * The sale's start and end that are still to come, when the effective price changes by itself.
	 *
	 * @param DateTimeImmutable $now Time.
	 * @return list<DateTimeImmutable>
	 */
	public function upcomingChanges( DateTimeImmutable $now ): array {
		if ( null === $this->sale ) {
			return array();
		}

		return array_values( array_filter( array( $this->saleStartsAt, $this->saleEndsAt ), static fn( ?DateTimeImmutable $at ): bool => null !== $at && $at > $now ) );
	}

	/**
	 * Whether two sets of prices are the same.
	 *
	 * @param self $other Other prices.
	 */
	public function equals( self $other ): bool {
		return self::same( $this->regular, $other->regular )
			&& self::same( $this->sale, $other->sale )
			&& $this->saleStartsAt?->getTimestamp() === $other->saleStartsAt?->getTimestamp()
			&& $this->saleEndsAt?->getTimestamp() === $other->saleEndsAt?->getTimestamp();
	}

	/**
	 * Whether two optional decimals are equal.
	 *
	 * @param Decimal|null $a One.
	 * @param Decimal|null $b Other.
	 */
	private static function same( ?Decimal $a, ?Decimal $b ): bool {
		return null === $a ? null === $b : null !== $b && $a->equals( $b );
	}

	/**
	 * The error for a price field that doesn't fit.
	 *
	 * @param string $field Field.
	 */
	private static function invalid( string $field ): DomainError {
		return DomainError::of( 'mercatus_product_invalid', array( 'field' => $field ) );
	}
}
