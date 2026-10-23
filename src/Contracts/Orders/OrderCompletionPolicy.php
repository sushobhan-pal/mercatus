<?php
/**
 * When a vendor order completes (ORD-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Orders;

/**
 * The single decision point for completion: it asks the enabled triggers in order and the first that fires
 * wins. An unpaid vendor order never completes through a trigger. The admin's explicit completion is a
 * command, not a trigger.
 */
interface OrderCompletionPolicy {

	/**
	 * The ID of the trigger that completes the vendor order now, or null when none does.
	 *
	 * @param VendorOrderCompletionState $state Vendor order state.
	 */
	public function firingTrigger( VendorOrderCompletionState $state ): ?string;
}
