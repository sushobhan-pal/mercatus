<?php
/**
 * The price a buyer pays now (TEST-3 row 6, CTL-22, CAT-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Catalog;

use DateTimeImmutable;
use DateTimeZone;
use Mercatus\Core\Catalog\Domain\EffectivePriceResolver;
use Mercatus\Core\Catalog\Domain\Prices;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Money\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * The sale price while the sale runs, from its start and before its end; the regular price otherwise. A
 * variable product's range spans its enabled variations. Prices that don't fit together are refused.
 */
final class EffectivePriceResolverTest extends TestCase {

	public function testWithoutASaleTheRegularPriceApplies(): void {
		$this->assertSame( '20', ( new EffectivePriceResolver() )->resolve( new Prices( Decimal::of( '20' ) ), self::utc( '2026-11-11' ) )?->toString() );
		$this->assertNull( ( new EffectivePriceResolver() )->resolve( Prices::none(), self::utc( '2026-11-11' ) ), 'A draft without a price.' );
	}

	public function testASaleWithoutDatesRunsUntilItIsRemoved(): void {
		$prices = new Prices( Decimal::of( '20' ), Decimal::of( '15.50' ) );

		$this->assertSame( '15.5', ( new EffectivePriceResolver() )->resolve( $prices, self::utc( '2030-01-01' ) )?->toString() );
	}

	public function testAScheduledSaleRunsFromItsStartUntilJustBeforeItsEnd(): void {
		$prices   = new Prices( Decimal::of( '20' ), Decimal::of( '15' ), self::utc( '2026-11-20 00:00:00' ), self::utc( '2026-11-27 00:00:00' ) );
		$resolver = new EffectivePriceResolver();

		$this->assertSame( '20', $resolver->resolve( $prices, self::utc( '2026-11-19 23:59:59' ) )?->toString() );
		$this->assertSame( '15', $resolver->resolve( $prices, self::utc( '2026-11-20 00:00:00' ) )?->toString() );
		$this->assertSame( '15', $resolver->resolve( $prices, self::utc( '2026-11-26 23:59:59' ) )?->toString() );
		$this->assertSame( '20', $resolver->resolve( $prices, self::utc( '2026-11-27 00:00:00' ) )?->toString() );
		$this->assertSame( '15', $resolver->resolve( $prices, self::utc( '2026-11-21' ), 42 )?->toString(), 'Free ignores the location.' );
	}

	public function testTheSalesUpcomingChangesAreItsFutureStartAndEnd(): void {
		$prices = new Prices( Decimal::of( '20' ), Decimal::of( '15' ), self::utc( '2026-11-20' ), self::utc( '2026-11-27' ) );

		$this->assertCount( 2, $prices->upcomingChanges( self::utc( '2026-11-11' ) ) );
		$this->assertEquals( array( self::utc( '2026-11-27' ) ), $prices->upcomingChanges( self::utc( '2026-11-21' ) ) );
		$this->assertSame( array(), ( new Prices( Decimal::of( '20' ) ) )->upcomingChanges( self::utc( '2026-11-11' ) ) );
	}

	public function testAVariableProductsRangeSpansItsVariations(): void {
		$range = ( new EffectivePriceResolver() )->range(
			array(
				new Prices( Decimal::of( '30' ) ),
				new Prices( Decimal::of( '20' ), Decimal::of( '12' ) ),
				Prices::none(),
				new Prices( Decimal::of( '25' ) ),
			),
			self::utc( '2026-11-11' )
		);

		$this->assertSame( array( '12', '30' ), array( $range[0]?->toString(), $range[1]?->toString() ) );
		$this->assertSame( array( null, null ), ( new EffectivePriceResolver() )->range( array(), self::utc( '2026-11-11' ) ) );
	}

	/**
	 * Prices that don't fit together.
	 *
	 * @return array<string, array{callable(): Prices, string}>
	 */
	public static function invalid(): array {
		return array(
			'negative'           => array( static fn(): Prices => new Prices( Decimal::of( '-1' ) ), 'regular_price' ),
			'sale without price' => array( static fn(): Prices => new Prices( null, Decimal::of( '5' ) ), 'sale_price' ),
			'sale not lower'     => array( static fn(): Prices => new Prices( Decimal::of( '5' ), Decimal::of( '5' ) ), 'sale_price' ),
			'dates without sale' => array( static fn(): Prices => new Prices( Decimal::of( '5' ), null, self::utc( '2026-11-20' ) ), 'sale_price' ),
			'end before start'   => array( static fn(): Prices => new Prices( Decimal::of( '5' ), Decimal::of( '4' ), self::utc( '2026-11-20' ), self::utc( '2026-11-19' ) ), 'sale_ends_at' ),
		);
	}

	/**
	 * Refuses prices that don't fit together, naming the field.
	 *
	 * @dataProvider invalid
	 * @param callable(): Prices $make  Makes the prices.
	 * @param string             $field The field named.
	 */
	public function testRefusesPricesThatDontFitTogether( callable $make, string $field ): void {
		try {
			$make();
			$this->fail( 'Accepted.' );
		} catch ( DomainError $error ) {
			$this->assertSame( array( 'mercatus_product_invalid', $field ), array( $error->errorCode, $error->details['field'] ?? null ) );
		}
	}

	/**
	 * A UTC time.
	 *
	 * @param string $time Time.
	 */
	private static function utc( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
