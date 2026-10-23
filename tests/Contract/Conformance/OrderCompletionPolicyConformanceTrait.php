<?php
/**
 * Conformance tests every OrderCompletionPolicy passes (TEST-1 Layer 3, ORD-12).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Conformance;

use DateTimeImmutable;
use Mercatus\Core\Contracts\Orders\CompletionTrigger;
use Mercatus\Core\Contracts\Orders\FulfilmentStatus;
use Mercatus\Core\Contracts\Orders\OrderCompletionPolicy;
use Mercatus\Core\Contracts\Orders\VendorOrderCompletionState;

/**
 * Use in a TestCase and implement createOrderCompletionPolicy().
 */
trait OrderCompletionPolicyConformanceTrait {

	/**
	 * Returns a policy with exactly these triggers enabled, asked in this order.
	 *
	 * @param list<CompletionTrigger> $triggers Enabled triggers.
	 */
	abstract protected function createOrderCompletionPolicy( array $triggers ): OrderCompletionPolicy;

	public function testOrderCompletionPolicyWithoutTriggersNeverCompletes(): void {
		$this->assertNull( $this->createOrderCompletionPolicy( array() )->firingTrigger( $this->completionState( true ) ) );
	}

	public function testOrderCompletionPolicyReturnsTheFirstTriggerThatFires(): void {
		$policy = $this->createOrderCompletionPolicy(
			array(
				$this->fixedTrigger( 'silent', false ),
				$this->fixedTrigger( 'first_firing', true ),
				$this->fixedTrigger( 'second_firing', true ),
			)
		);

		$this->assertSame( 'first_firing', $policy->firingTrigger( $this->completionState( true ) ) );
	}

	public function testOrderCompletionPolicyReturnsNullWhenNoTriggerFires(): void {
		$policy = $this->createOrderCompletionPolicy( array( $this->fixedTrigger( 'silent', false ) ) );

		$this->assertNull( $policy->firingTrigger( $this->completionState( true ) ) );
	}

	public function testOrderCompletionPolicyNeverCompletesAnUnpaidOrder(): void {
		$policy = $this->createOrderCompletionPolicy( array( $this->fixedTrigger( 'always', true ) ) );

		$this->assertNull( $policy->firingTrigger( $this->completionState( false ) ) );
	}

	/**
	 * A trigger with a fixed answer.
	 *
	 * @param string $id    Trigger ID.
	 * @param bool   $fires Answer to every state.
	 */
	private function fixedTrigger( string $id, bool $fires ): CompletionTrigger {
		return new class( $id, $fires ) implements CompletionTrigger {

			/**
			 * Creates the trigger.
			 *
			 * @param string $id    Trigger ID.
			 * @param bool   $fires Answer to every state.
			 */
			public function __construct( private string $id, private bool $fires ) {}

			public function id(): string {
				return $this->id;
			}

			public function fires( VendorOrderCompletionState $state ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fixed answer by design.
				return $this->fires;
			}
		};
	}

	/**
	 * A shipped vendor order, paid or not.
	 *
	 * @param bool $paid Whether the parent order is paid.
	 */
	private function completionState( bool $paid ): VendorOrderCompletionState {
		$now = new DateTimeImmutable( '2026-10-23 12:00:00' );

		return new VendorOrderCompletionState( 1, $paid, false, FulfilmentStatus::Shipped, $now, null, false, false, false, $now );
	}
}
