<?php
/**
 * Conformance tests every StoreTaxPolicy passes (TEST-1 Layer 3, TAX-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use Mercatus\Core\Contracts\Tax\StoreTaxPolicy;

/**
 * Use in a TestCase and implement createStoreTaxPolicy().
 */
trait StoreTaxPolicyConformanceTrait {

	/**
	 * Returns a policy whose "Enable store tax" setting is on or off, with no other overrides.
	 *
	 * @param bool $enabled Setting value.
	 */
	abstract protected function createStoreTaxPolicy( bool $enabled ): StoreTaxPolicy;

	public function testStoreTaxPolicyFollowsTheSettingForEveryVendor(): void {
		$on  = $this->createStoreTaxPolicy( true );
		$off = $this->createStoreTaxPolicy( false );

		foreach ( array( 1, 2, 999999 ) as $vendor_id ) {
			$this->assertTrue( $on->isEnabledFor( $vendor_id ) );
			$this->assertFalse( $off->isEnabledFor( $vendor_id ) );
		}
	}

	public function testStoreTaxPolicyIsDeterministic(): void {
		$policy = $this->createStoreTaxPolicy( true );

		$this->assertSame( $policy->isEnabledFor( 7 ), $policy->isEnabledFor( 7 ) );
	}
}
