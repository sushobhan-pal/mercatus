<?php
/**
 * Runs the CommissionRuleSource conformance tests against the in-memory fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Commission\CommissionContext;
use Mercatus\Core\Contracts\Commission\CommissionRule;
use Mercatus\Core\Contracts\Commission\CommissionRuleSource;
use Mercatus\Core\Shared\Money\Money;
use Mercatus\Core\Tests\Contract\Conformance\CommissionRuleSourceConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class InMemoryCommissionRuleSourceTest extends TestCase {

	use CommissionRuleSourceConformanceTrait;

	protected function createCommissionRuleSource(): CommissionRuleSource {
		return new InMemoryCommissionRuleSource(
			'pro.commissions.vendor',
			40,
			array( 7 => new CommissionRule( 'vendor-7', 1, '12.5', Money::of( 500, 'INR' ) ) )
		);
	}

	protected function commissionContextWithRule(): CommissionContext {
		return $this->context( 7 );
	}

	protected function commissionContextsWithoutRule(): array {
		return array( $this->context( 8 ) );
	}

	/**
	 * A line sold by a vendor.
	 *
	 * @param int $vendor_id Vendor ID.
	 */
	private function context( int $vendor_id ): CommissionContext {
		return new CommissionContext( $vendor_id, 42, array( 3 ), Money::of( 10000, 'INR' ), Money::of( 25000, 'INR' ) );
	}
}
