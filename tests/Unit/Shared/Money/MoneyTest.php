<?php
/**
 * Money and Currency value objects.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Shared\Money;

use InvalidArgumentException;
use Mercatus\Core\Shared\Money\Currency;
use Mercatus\Core\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Integer minor units with an ISO 4217 currency (G-MONEY-1).
 */
final class MoneyTest extends TestCase {

	/**
	 * Malformed currency codes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalidCurrencyCodes(): array {
		return array(
			'lower case' => array( 'inr' ),
			'too short'  => array( 'IN' ),
			'too long'   => array( 'INRS' ),
			'digits'     => array( '356' ),
			'empty'      => array( '' ),
		);
	}

	/**
	 * Refuses a malformed currency code.
	 *
	 * @dataProvider invalidCurrencyCodes
	 * @param string $code Currency code.
	 */
	public function testRefusesAMalformedCurrencyCode( string $code ): void {
		$this->expectException( InvalidArgumentException::class );
		new Currency( $code );
	}

	public function testComparesCurrenciesByCode(): void {
		$this->assertTrue( ( new Currency( 'INR' ) )->equals( new Currency( 'INR' ) ) );
		$this->assertFalse( ( new Currency( 'INR' ) )->equals( new Currency( 'USD' ) ) );
	}

	public function testBuildsFromMinorUnitsAndACode(): void {
		$money = Money::of( -2550, 'EUR' );

		$this->assertSame( -2550, $money->minor );
		$this->assertSame( 'EUR', $money->currency->code );
	}

	public function testComparesAmountAndCurrency(): void {
		$amount = Money::of( 100, 'INR' );

		$this->assertTrue( $amount->equals( Money::of( 100, 'INR' ) ) );
		$this->assertFalse( $amount->equals( Money::of( 101, 'INR' ) ) );
		$this->assertFalse( $amount->equals( Money::of( 100, 'USD' ) ) );
	}

	public function testKnowsWhetherItIsPositive(): void {
		$this->assertTrue( Money::of( 1, 'INR' )->isPositive() );
		$this->assertFalse( Money::of( 0, 'INR' )->isPositive() );
		$this->assertFalse( Money::of( -1, 'INR' )->isPositive() );
	}
}
