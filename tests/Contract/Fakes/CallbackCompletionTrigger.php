<?php
/**
 * CompletionTrigger built from a callback, for tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Contract\Fakes;

use Closure;
use Mercatus\Core\Contracts\Orders\CompletionTrigger;
use Mercatus\Core\Contracts\Orders\VendorOrderCompletionState;

/**
 * Fires when its condition holds.
 */
final class CallbackCompletionTrigger implements CompletionTrigger {

	/**
	 * Creates the trigger.
	 *
	 * @param string                                    $id        Trigger ID.
	 * @param Closure(VendorOrderCompletionState): bool $condition When the trigger fires.
	 */
	public function __construct( private string $id, private Closure $condition ) {}

	public function id(): string {
		return $this->id;
	}

	public function fires( VendorOrderCompletionState $state ): bool {
		return ( $this->condition )( $state );
	}
}
