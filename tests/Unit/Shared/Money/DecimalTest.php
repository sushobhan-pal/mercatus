<?php
/**
 * Exact decimals for unit prices and quantities (DATA-1, G-MONEY-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Shared\Money;

use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Six places, as `DECIMAL(20,6)` stores them, with no float anywhere.
 */
final class DecimalTest extends TestCase {

	public function testParsesAndPrintsExactly(): void {
		$this->assertSame( '12.5', Decimal::of( '12.5' )->toString() );
		$this->assertSame( '12.500000', Decimal::of( '12.5' )->toDatabase() );
		$this->assertSame( '10', Decimal::of( '10.000000' )->toString() );
		$this->assertSame( '7', Decimal::of( 7 )->toString() );
		$this->assertSame( '-0.25', Decimal::of( '-0.25' )->toString() );
		$this->assertSame( '0.000001', Decimal::of( '0.000001' )->toString() );
		$this->assertSame( '0', Decimal::zero()->toString() );
		$this->assertSame( '999999999999.999999', Decimal::of( '999999999999.999999' )->toString() );
	}

	public function testAddsAndComparesWithoutRounding(): void {
		$sum = Decimal::of( '0.1' )->add( Decimal::of( '0.2' ) );

		$this->assertTrue( $sum->equals( Decimal::of( '0.3' ) ) );
		$this->assertSame( '-0.1', Decimal::of( '0.2' )->subtract( Decimal::of( '0.3' ) )->toString() );
		$this->assertSame( -1, Decimal::of( '9.99' )->compare( Decimal::of( '10' ) ) );
		$this->assertSame( 0, Decimal::of( '10' )->compare( Decimal::of( '10.0' ) ) );
		$this->assertTrue( Decimal::of( '-1' )->isNegative() );
		$this->assertTrue( Decimal::of( '0.000' )->isZero() );
	}

	public function testKnowsWholeNumbers(): void {
		$this->assertTrue( Decimal::of( '12' )->isWhole() );
		$this->assertFalse( Decimal::of( '12.5' )->isWhole() );
		$this->assertSame( 12, Decimal::of( '12' )->toInt() );
		$this->assertSame( -3, Decimal::of( '-3' )->toInt() );
	}

	/**
	 * Values that aren't decimals Mercatus stores.
	 *
	 * @return array<string, array{string|int}>
	 */
	public static function invalid(): array {
		return array(
			'seven places'  => array( '1.1234567' ),
			'exponent'      => array( '1e3' ),
			'empty'         => array( '' ),
			'trailing dot'  => array( '1.' ),
			'words'         => array( 'twelve' ),
			'a trillion'    => array( '1000000000000' ),
			'big integer'   => array( 1000000000000 ),
			'two signs'     => array( '--1' ),
			'leading plus'  => array( '+1' ),
			'comma decimal' => array( '1,5' ),
		);
	}

	/**
	 * Refuses anything that isn't an exact decimal in range.
	 *
	 * @dataProvider invalid
	 * @param string|int $value Value.
	 */
	public function testRefusesWhatItCannotHoldExactly( string|int $value ): void {
		$this->assertFalse( Decimal::valid( $value ) );
		$this->expectException( InvalidArgumentException::class );
		Decimal::of( $value );
	}

	public function testNeverAcceptsAFloat(): void {
		$this->assertFalse( Decimal::valid( 1.5 ) );
	}
}
