<?php
/**
 * What a commission rule source looks at (MON-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Commission;

use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Money;

/**
 * One allocation line being commissioned at Stage 1. Sources look up anything else they need, such as a
 * vendor's membership plan or total sales, themselves.
 */
final readonly class CommissionContext {

	/**
	 * Creates a commission context.
	 *
	 * @param int       $vendorId          Selling vendor.
	 * @param int       $productId         WooCommerce product.
	 * @param list<int> $categoryIds       Product categories.
	 * @param Money     $lineAmount        Line subtotal.
	 * @param Money     $vendorOrderAmount Subtotal of the vendor's whole order, for cart-value rules.
	 * @throws InvalidArgumentException When an ID isn't positive or the currencies differ.
	 */
	public function __construct(
		public int $vendorId,
		public int $productId,
		public array $categoryIds,
		public Money $lineAmount,
		public Money $vendorOrderAmount,
	) {
		if ( $vendorId < 1 || $productId < 1 ) {
			throw new InvalidArgumentException( 'Vendor and product IDs are positive integers.' );
		}
		if ( ! $lineAmount->currency->equals( $vendorOrderAmount->currency ) ) {
			throw new InvalidArgumentException( 'Line and vendor-order amounts share a currency.' );
		}
	}
}
