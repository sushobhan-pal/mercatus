<?php
/**
 * Resolver input for one line (TAX-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

use DateTimeImmutable;
use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Currency;

/**
 * Everything that decides which taxes apply to one line: seller, tax class, fulfilment method, origin,
 * destination, time and currency. With store tax on, the origin is the vendor's ship-from address.
 */
final readonly class TaxContext {

	/**
	 * Creates a tax context.
	 *
	 * @param int               $vendorId    Selling vendor.
	 * @param string            $taxClass    Product tax class slug; empty for the standard class, as in WooCommerce.
	 * @param FulfilmentMethod  $method      Delivery, pickup or digital.
	 * @param TaxAddress        $origin      Where the line ships from.
	 * @param TaxAddress        $destination Customer address, or the pickup point for pickup lines.
	 * @param DateTimeImmutable $at          Transaction time.
	 * @param Currency          $currency    Order currency.
	 * @throws InvalidArgumentException When the vendor ID isn't positive.
	 */
	public function __construct(
		public int $vendorId,
		public string $taxClass,
		public FulfilmentMethod $method,
		public TaxAddress $origin,
		public TaxAddress $destination,
		public DateTimeImmutable $at,
		public Currency $currency,
	) {
		if ( $vendorId < 1 ) {
			throw new InvalidArgumentException( 'A vendor ID is a positive integer.' );
		}
	}
}
