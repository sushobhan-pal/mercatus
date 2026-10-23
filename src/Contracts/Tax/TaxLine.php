<?php
/**
 * One line handed to a tax-rate resolver (TAX-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Tax;

use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Money;

/**
 * A cart or order line: its key, tax context and taxable amount. Rate-based resolvers ignore the amount;
 * amount-based providers need it.
 */
final readonly class TaxLine {

	/**
	 * Creates a tax line.
	 *
	 * @param string     $key     Line key, unique within the request.
	 * @param TaxContext $context Tax context of the line.
	 * @param Money      $amount  Taxable amount, in the context's currency.
	 * @throws InvalidArgumentException When the key is empty or the currencies differ.
	 */
	public function __construct( public string $key, public TaxContext $context, public Money $amount ) {
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'A tax line needs a key.' );
		}
		if ( ! $amount->currency->equals( $context->currency ) ) {
			throw new InvalidArgumentException( 'A tax line amount is in its context currency.' );
		}
	}
}
