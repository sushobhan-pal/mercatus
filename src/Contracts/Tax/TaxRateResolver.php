<?php
/**
 * Chooses the taxes for a vendor's lines (TAX-5, TAX-11).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

/**
 * Version 1 ships two resolvers, WooCommerce rates and vendor store rates; automated providers arrive as
 * resolvers in their own modules. WooCommerce always does the arithmetic with what a resolver returns.
 */
interface TaxRateResolver {

	/**
	 * Stable identifier, for example woocommerce-rates or vendor-store-rates.
	 */
	public function id(): string;

	/**
	 * Resolves the taxes for one vendor's lines. It's called once per vendor, so every line shares the vendor
	 * and origin; lines left out of the result are untaxed.
	 *
	 * @param list<TaxLine> $lines Lines of one vendor, with unique keys.
	 */
	public function resolve( array $lines ): TaxResolution;
}
