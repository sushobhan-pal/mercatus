<?php
/**
 * The price a buyer pays now (CTL-22, CAT-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

use DateTimeImmutable;
use Mercatus\Core\Shared\Money\Decimal;

/**
 * The sale price while the sale runs, and the regular price otherwise. It takes the location too, because
 * Pro's location-specific prices override it there (CAT-1); in Free an offer's price applies everywhere.
 */
final class EffectivePriceResolver {

	/**
	 * One offer's or variation's effective price.
	 *
	 * @param Prices            $prices      Prices.
	 * @param DateTimeImmutable $now         Time.
	 * @param int|null          $location_id Fulfilment location, for Pro's overrides; Free ignores it.
	 */
	public function resolve( Prices $prices, DateTimeImmutable $now, ?int $location_id = null ): ?Decimal {
		unset( $location_id );

		return $prices->onSale( $now ) ? $prices->sale : $prices->regular;
	}

	/**
	 * The lowest and highest effective prices among a variable product's variations; nulls without any.
	 *
	 * @param list<Prices>      $variations  The enabled variations' prices.
	 * @param DateTimeImmutable $now         Time.
	 * @param int|null          $location_id Fulfilment location.
	 * @return array{0: Decimal|null, 1: Decimal|null}
	 */
	public function range( array $variations, DateTimeImmutable $now, ?int $location_id = null ): array {
		$low  = null;
		$high = null;
		foreach ( $variations as $prices ) {
			$price = $this->resolve( $prices, $now, $location_id );
			if ( null === $price ) {
				continue;
			}
			$low  = null === $low || $price->compare( $low ) < 0 ? $price : $low;
			$high = null === $high || $price->compare( $high ) > 0 ? $price : $high;
		}

		return array( $low, $high );
	}
}
