<?php
/**
 * Runs the CompletionTrigger conformance tests against the callback fake.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use DateTimeImmutable;
use Mercatus\Core\Contracts\Orders\CompletionTrigger;
use Mercatus\Core\Contracts\Orders\FulfilmentStatus;
use Mercatus\Core\Contracts\Orders\VendorOrderCompletionState;
use Mercatus\Core\Tests\Contract\Conformance\CompletionTriggerConformanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trait and the fake agree.
 */
final class CallbackCompletionTriggerTest extends TestCase {

	use CompletionTriggerConformanceTrait;

	protected function createCompletionTrigger(): CompletionTrigger {
		return new CallbackCompletionTrigger(
			'customer_confirmed',
			static fn( VendorOrderCompletionState $state ): bool => $state->customerConfirmedReceipt
		);
	}

	protected function completionStateThatFires(): VendorOrderCompletionState {
		return $this->state( true );
	}

	protected function completionStateThatDoesNotFire(): VendorOrderCompletionState {
		return $this->state( false );
	}

	/**
	 * A paid, delivered vendor order.
	 *
	 * @param bool $confirmed Whether the customer confirmed receipt.
	 */
	private function state( bool $confirmed ): VendorOrderCompletionState {
		$now = new DateTimeImmutable( '2026-10-23 12:00:00' );

		return new VendorOrderCompletionState( 1, true, false, FulfilmentStatus::Delivered, $now, $now, false, $confirmed, false, $now );
	}
}
