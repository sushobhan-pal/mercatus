<?php
/**
 * In-memory TaxRateResolver for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Tax\TaxRate;
use Mercatus\Core\Contracts\Tax\TaxRateResolver;
use Mercatus\Core\Contracts\Tax\TaxResolution;

/**
 * Returns fixed rates per tax class; lines of other classes are untaxed.
 */
final class InMemoryTaxRateResolver implements TaxRateResolver {

	/**
	 * Creates the resolver.
	 *
	 * @param array<string, list<TaxRate>> $ratesByClass Rates by tax class slug.
	 */
	public function __construct( private array $ratesByClass ) {}

	public function id(): string {
		return 'in-memory-rates';
	}

	public function resolve( array $lines ): TaxResolution {
		$rates = array();
		foreach ( $lines as $line ) {
			if ( isset( $this->ratesByClass[ $line->context->taxClass ] ) ) {
				$rates[ $line->key ] = $this->ratesByClass[ $line->context->taxClass ];
			}
		}

		return new TaxResolution( $rates );
	}
}
