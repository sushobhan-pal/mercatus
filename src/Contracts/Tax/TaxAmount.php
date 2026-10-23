<?php
/**
 * A tax amount from an amount-based provider (TAX-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Money;

/**
 * A ready-made tax amount for a line, for providers that calculate amounts instead of returning rates.
 */
final readonly class TaxAmount {

	/**
	 * Creates a tax amount.
	 *
	 * @param string   $label  Label shown on orders.
	 * @param Money    $amount Tax amount, zero or more.
	 * @param int|null $rateId Rate ID for WooCommerce's order tax line, when the provider has one.
	 * @throws InvalidArgumentException When the label is empty or the amount is negative.
	 */
	public function __construct( public string $label, public Money $amount, public ?int $rateId = null ) {
		if ( '' === $label || $amount->minor < 0 ) {
			throw new InvalidArgumentException( 'A tax amount needs a label and a non-negative amount.' );
		}
	}
}
