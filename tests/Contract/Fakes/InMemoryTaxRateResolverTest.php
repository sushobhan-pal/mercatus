<?php
/**
 * Runs the TaxRateResolver conformance tests against the in-memory fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use DateTimeImmutable;
use Mercatus\Core\Contracts\Tax\FulfilmentMethod;
use Mercatus\Core\Contracts\Tax\TaxAddress;
use Mercatus\Core\Contracts\Tax\TaxContext;
use Mercatus\Core\Contracts\Tax\TaxLine;
use Mercatus\Core\Contracts\Tax\TaxRate;
use Mercatus\Core\Contracts\Tax\TaxRateResolver;
use Mercatus\Core\Shared\Money\Currency;
use Mercatus\Core\Shared\Money\Money;
use Mercatus\Core\Tests\Contract\Conformance\TaxRateResolverConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class InMemoryTaxRateResolverTest extends TestCase {

	use TaxRateResolverConformanceTrait;

	protected function createTaxRateResolver(): TaxRateResolver {
		return new InMemoryTaxRateResolver(
			array(
				''             => array( new TaxRate( 1, 'CGST', '9.0000' ), new TaxRate( 2, 'SGST', '9.0000' ) ),
				'reduced-rate' => array( new TaxRate( 3, 'GST', '5.0000' ) ),
			)
		);
	}

	protected function taxLinesForConformance(): array {
		return array(
			new TaxLine( 'line-1', $this->context( '' ), Money::of( 10000, 'INR' ) ),
			new TaxLine( 'line-2', $this->context( 'reduced-rate' ), Money::of( 2500, 'INR' ) ),
			new TaxLine( 'line-3', $this->context( 'zero-rate' ), Money::of( 500, 'INR' ) ),
		);
	}

	/**
	 * A delivery from one vendor in Kolkata to Mumbai.
	 *
	 * @param string $tax_class Tax class slug.
	 */
	private function context( string $tax_class ): TaxContext {
		return new TaxContext(
			7,
			$tax_class,
			FulfilmentMethod::Delivery,
			new TaxAddress( 'IN', 'WB', '700001', 'Kolkata' ),
			new TaxAddress( 'IN', 'MH', '400001', 'Mumbai' ),
			new DateTimeImmutable( '2026-10-23 12:00:00' ),
			new Currency( 'INR' )
		);
	}
}
