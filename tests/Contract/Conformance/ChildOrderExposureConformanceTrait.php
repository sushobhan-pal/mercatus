<?php
/**
 * Conformance tests every ChildOrderExposure passes (TEST-1 Layer 3, ORD-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use Mercatus\Core\Contracts\Orders\ChildOrderExposure;

/**
 * Use in a TestCase and implement the three methods below.
 */
trait ChildOrderExposureConformanceTrait {

	/**
	 * Returns the policy under test.
	 */
	abstract protected function createChildOrderExposure(): ChildOrderExposure;

	/**
	 * Returns the ID of a Mercatus child order the policy can see.
	 */
	abstract protected function childOrderId(): int;

	/**
	 * Returns the ID of an order that isn't a Mercatus child order.
	 */
	abstract protected function nonChildOrderId(): int;

	public function testChildOrderExposureHidesChildOrdersInTheVersionOneContexts(): void {
		$exposure = $this->createChildOrderExposure();

		foreach ( $this->versionOneContexts() as $context ) {
			$this->assertFalse( $exposure->isVisibleIn( $context, $this->childOrderId() ), $context );
		}
	}

	public function testChildOrderExposureLeavesOtherOrdersVisible(): void {
		$exposure = $this->createChildOrderExposure();

		foreach ( $this->versionOneContexts() as $context ) {
			$this->assertTrue( $exposure->isVisibleIn( $context, $this->nonChildOrderId() ), $context );
		}
	}

	/**
	 * Contexts ORD-7 hides child orders from in version 1.
	 *
	 * @return list<string>
	 */
	private function versionOneContexts(): array {
		return array(
			ChildOrderExposure::CONTEXT_ANALYTICS,
			ChildOrderExposure::CONTEXT_MY_ACCOUNT,
			ChildOrderExposure::CONTEXT_CUSTOMER_EMAILS,
		);
	}
}
