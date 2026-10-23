<?php
/**
 * Conformance tests every TaxRateResolver passes (TEST-1 Layer 3, TAX-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use Mercatus\Core\Contracts\Tax\TaxLine;
use Mercatus\Core\Contracts\Tax\TaxRateResolver;
use Mercatus\Core\Tests\Contract\Support\ContractIdentifier;

/**
 * Use in a TestCase and implement createTaxRateResolver() and taxLinesForConformance().
 */
trait TaxRateResolverConformanceTrait {

	/**
	 * Returns the resolver under test.
	 */
	abstract protected function createTaxRateResolver(): TaxRateResolver;

	/**
	 * Returns at least two lines of one vendor, with unique keys, that the resolver taxes.
	 *
	 * @return list<TaxLine>
	 */
	abstract protected function taxLinesForConformance(): array;

	public function testTaxRateResolverHasAStableId(): void {
		$resolver = $this->createTaxRateResolver();

		$this->assertMatchesRegularExpression( ContractIdentifier::PATTERN, $resolver->id() );
		$this->assertSame( $resolver->id(), $resolver->id() );
	}

	public function testTaxRateResolverResolvesNothingForNoLines(): void {
		$resolution = $this->createTaxRateResolver()->resolve( array() );

		$this->assertSame( array(), $resolution->rates );
		$this->assertSame( array(), $resolution->amounts );
	}

	public function testTaxRateResolverAnswersOnlyForTheLinesItWasGiven(): void {
		$lines      = $this->taxLinesForConformance();
		$keys       = array_map( static fn( TaxLine $line ): string => $line->key, $lines );
		$resolution = $this->createTaxRateResolver()->resolve( $lines );
		$answered   = array_merge( array_keys( $resolution->rates ), array_keys( $resolution->amounts ) );

		$this->assertGreaterThanOrEqual( 2, count( $lines ) );
		$this->assertNotSame( array(), $answered, 'The conformance lines must be taxed.' );
		$this->assertSame( array(), array_diff( $answered, $keys ) );
	}

	public function testTaxRateResolverIsDeterministicAndOrderIndependent(): void {
		$lines    = $this->taxLinesForConformance();
		$resolver = $this->createTaxRateResolver();
		$first    = $resolver->resolve( $lines );

		$this->assertEquals( $first, $resolver->resolve( $lines ) );

		$reversed = $resolver->resolve( array_reverse( $lines ) );
		foreach ( $lines as $line ) {
			$this->assertEquals( $first->ratesFor( $line->key ), $reversed->ratesFor( $line->key ) );
			$this->assertEquals( $first->amountsFor( $line->key ), $reversed->amountsFor( $line->key ) );
		}
	}

	public function testTaxRateResolverReturnsAmountsInTheLineCurrency(): void {
		$lines      = $this->taxLinesForConformance();
		$resolution = $this->createTaxRateResolver()->resolve( $lines );

		foreach ( $lines as $line ) {
			foreach ( $resolution->amountsFor( $line->key ) as $amount ) {
				$this->assertTrue( $amount->amount->currency->equals( $line->context->currency ) );
			}
		}
		$this->addToAssertionCount( 1 );
	}
}
