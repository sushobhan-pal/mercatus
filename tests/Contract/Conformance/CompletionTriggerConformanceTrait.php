<?php
/**
 * Conformance tests every CompletionTrigger passes (TEST-1 Layer 3, ORD-12).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use Mercatus\Core\Contracts\Orders\CompletionTrigger;
use Mercatus\Core\Contracts\Orders\VendorOrderCompletionState;
use Mercatus\Core\Tests\Contract\Support\ContractIdentifier;

/**
 * Use in a TestCase and implement the three methods below.
 */
trait CompletionTriggerConformanceTrait {

	/**
	 * Returns the trigger under test.
	 */
	abstract protected function createCompletionTrigger(): CompletionTrigger;

	/**
	 * Returns a paid vendor order state in which the trigger fires.
	 */
	abstract protected function completionStateThatFires(): VendorOrderCompletionState;

	/**
	 * Returns a paid vendor order state in which the trigger doesn't fire.
	 */
	abstract protected function completionStateThatDoesNotFire(): VendorOrderCompletionState;

	public function testCompletionTriggerHasAStableId(): void {
		$trigger = $this->createCompletionTrigger();

		$this->assertMatchesRegularExpression( ContractIdentifier::PATTERN, $trigger->id() );
		$this->assertSame( $trigger->id(), $trigger->id() );
	}

	public function testCompletionTriggerFiresOnlyWhenItsConditionHolds(): void {
		$trigger = $this->createCompletionTrigger();

		$this->assertTrue( $trigger->fires( $this->completionStateThatFires() ) );
		$this->assertFalse( $trigger->fires( $this->completionStateThatDoesNotFire() ) );
	}

	public function testCompletionTriggerAnswersWithoutSideEffects(): void {
		$trigger = $this->createCompletionTrigger();
		$state   = $this->completionStateThatFires();

		$this->assertSame( $trigger->fires( $state ), $trigger->fires( $state ) );
	}
}
