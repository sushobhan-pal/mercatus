<?php
/**
 * Reference OrderCompletionPolicy for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Mercatus\Core\Contracts\Orders\CompletionTrigger;
use Mercatus\Core\Contracts\Orders\OrderCompletionPolicy;
use Mercatus\Core\Contracts\Orders\VendorOrderCompletionState;

/**
 * Asks its triggers in order; core.orders replaces it with the settings-driven policy.
 */
final class FirstFiringCompletionPolicy implements OrderCompletionPolicy {

	/**
	 * Creates the policy.
	 *
	 * @param list<CompletionTrigger> $triggers Enabled triggers in order.
	 */
	public function __construct( private array $triggers ) {}

	public function firingTrigger( VendorOrderCompletionState $state ): ?string {
		if ( ! $state->paid ) {
			return null;
		}
		foreach ( $this->triggers as $trigger ) {
			if ( $trigger->fires( $state ) ) {
				return $trigger->id();
			}
		}

		return null;
	}
}
