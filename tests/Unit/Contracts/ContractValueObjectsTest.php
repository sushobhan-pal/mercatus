<?php
/**
 * Invariants of the contract value objects (GW-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Contracts;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Mercatus\Core\Contracts\Catalog\CatalogQuery;
use Mercatus\Core\Contracts\Commission\CommissionContext;
use Mercatus\Core\Contracts\Commission\CommissionRule;
use Mercatus\Core\Contracts\Orders\FulfilmentStatus;
use Mercatus\Core\Contracts\Orders\VendorOrderCompletionState;
use Mercatus\Core\Contracts\Payouts\PayoutInstruction;
use Mercatus\Core\Contracts\Payouts\PayoutResult;
use Mercatus\Core\Contracts\Payouts\PayoutStatus;
use Mercatus\Core\Contracts\Tax\FulfilmentMethod;
use Mercatus\Core\Contracts\Tax\TaxAddress;
use Mercatus\Core\Contracts\Tax\TaxAmount;
use Mercatus\Core\Contracts\Tax\TaxContext;
use Mercatus\Core\Contracts\Tax\TaxLine;
use Mercatus\Core\Contracts\Tax\TaxRate;
use Mercatus\Core\Contracts\Tax\TaxResolution;
use Mercatus\Core\Shared\Money\Currency;
use Mercatus\Core\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Each value object refuses to exist in an invalid state, so implementations never see one.
 */
final class ContractValueObjectsTest extends TestCase {

	/**
	 * Constructions that must be refused.
	 *
	 * @return array<string, array{Closure(): mixed}>
	 */
	public static function invalidConstructions(): array {
		return array(
			'tax address with a three-letter country' => array( static fn() => new TaxAddress( 'IND' ) ),
			'tax context for vendor 0'                => array( static fn() => self::taxContext( 0 ) ),
			'tax line without a key'                  => array( static fn() => new TaxLine( '', self::taxContext(), Money::of( 100, 'INR' ) ) ),
			'tax line in another currency'            => array( static fn() => new TaxLine( 'line-1', self::taxContext(), Money::of( 100, 'USD' ) ) ),
			'tax rate with ID 0'                      => array( static fn() => new TaxRate( 0, 'GST', '5' ) ),
			'tax rate without a label'                => array( static fn() => new TaxRate( 1, '', '5' ) ),
			'tax rate with a negative percent'        => array( static fn() => new TaxRate( 1, 'GST', '-5' ) ),
			'tax rate with a non-numeric percent'     => array( static fn() => new TaxRate( 1, 'GST', '5%' ) ),
			'tax rate with priority 0'                => array( static fn() => new TaxRate( 1, 'GST', '5', 0 ) ),
			'tax amount below zero'                   => array( static fn() => new TaxAmount( 'GST', Money::of( -1, 'INR' ) ) ),
			'tax amount without a label'              => array( static fn() => new TaxAmount( '', Money::of( 1, 'INR' ) ) ),
			'line with rates and amounts'             => array(
				static fn() => new TaxResolution(
					array( 'line-1' => array( new TaxRate( 1, 'GST', '5' ) ) ),
					array( 'line-1' => array( new TaxAmount( 'GST', Money::of( 5, 'INR' ) ) ) )
				),
			),
			'commission context for product 0'        => array( static fn() => new CommissionContext( 7, 0, array(), Money::of( 1, 'INR' ), Money::of( 1, 'INR' ) ) ),
			'commission context in two currencies'    => array( static fn() => new CommissionContext( 7, 42, array(), Money::of( 1, 'INR' ), Money::of( 1, 'USD' ) ) ),
			'commission rule without a basis'         => array( static fn() => new CommissionRule( 'global', 1 ) ),
			'commission rule at version 0'            => array( static fn() => new CommissionRule( 'global', 0, '10' ) ),
			'commission rule above 100 percent'       => array( static fn() => new CommissionRule( 'global', 1, '100.01' ) ),
			'commission rule with a negative flat'    => array( static fn() => new CommissionRule( 'global', 1, null, Money::of( -1, 'INR' ) ) ),
			'completion state for vendor order 0'     => array( static fn() => self::completionState( 0 ) ),
			'payout of zero'                          => array( static fn() => new PayoutInstruction( 'key', 7, Money::of( 0, 'INR' ), 'acct_7' ) ),
			'payout without an idempotency key'       => array( static fn() => new PayoutInstruction( '', 7, Money::of( 1, 'INR' ), 'acct_7' ) ),
			'payout without an account'               => array( static fn() => new PayoutInstruction( 'key', 7, Money::of( 1, 'INR' ), '' ) ),
			'failed payout without a code'            => array( static fn() => new PayoutResult( PayoutStatus::Failed, 'po_1' ) ),
			'paid payout with a failure code'         => array( static fn() => new PayoutResult( PayoutStatus::Paid, 'po_1', 'account_closed' ) ),
			'payout result without a reference'       => array( static fn() => new PayoutResult( PayoutStatus::Pending, '' ) ),
			'catalog page of 0'                       => array( static fn() => new CatalogQuery( perPage: 0 ) ),
			'catalog page above the maximum'          => array( static fn() => new CatalogQuery( perPage: CatalogQuery::MAX_PER_PAGE + 1 ) ),
		);
	}

	/**
	 * Refuses an invalid construction.
	 *
	 * @dataProvider invalidConstructions
	 * @param Closure(): mixed $construct Builds the value object.
	 */
	public function testRefusesAnInvalidValue( Closure $construct ): void {
		$this->expectException( InvalidArgumentException::class );
		$construct();
	}

	public function testAcceptsTheBoundaryValues(): void {
		$this->assertSame( '0', ( new TaxRate( 1, 'Zero', '0' ) )->percent );
		$this->assertSame( '100', ( new CommissionRule( 'global', 1, '100' ) )->percent );
		$this->assertSame( 0, ( new CommissionRule( 'global', 1, null, Money::of( 0, 'INR' ) ) )->flat?->minor );
		$this->assertSame( 0, ( new TaxAmount( 'GST', Money::of( 0, 'INR' ) ) )->amount->minor );
		$this->assertSame( CatalogQuery::MAX_PER_PAGE, ( new CatalogQuery( perPage: CatalogQuery::MAX_PER_PAGE ) )->perPage );
	}

	public function testTaxResolutionAnswersEmptyForAnUntaxedLine(): void {
		$rate       = new TaxRate( 1, 'GST', '5' );
		$resolution = new TaxResolution( array( 'line-1' => array( $rate ) ) );

		$this->assertSame( array( $rate ), $resolution->ratesFor( 'line-1' ) );
		$this->assertSame( array(), $resolution->ratesFor( 'line-2' ) );
		$this->assertSame( array(), $resolution->amountsFor( 'line-1' ) );
	}

	/**
	 * A delivery tax context.
	 *
	 * @param int $vendor_id Vendor ID.
	 */
	private static function taxContext( int $vendor_id = 7 ): TaxContext {
		return new TaxContext(
			$vendor_id,
			'',
			FulfilmentMethod::Delivery,
			new TaxAddress( 'IN', 'WB' ),
			new TaxAddress( 'IN', 'MH' ),
			new DateTimeImmutable( '2026-10-23 12:00:00' ),
			new Currency( 'INR' )
		);
	}

	/**
	 * An unfulfilled vendor order.
	 *
	 * @param int $vendor_order_id Vendor order ID.
	 */
	private static function completionState( int $vendor_order_id ): VendorOrderCompletionState {
		$now = new DateTimeImmutable( '2026-10-23 12:00:00' );

		return new VendorOrderCompletionState( $vendor_order_id, true, false, FulfilmentStatus::Unfulfilled, null, null, false, false, false, $now );
	}
}
