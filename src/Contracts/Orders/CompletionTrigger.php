<?php
/**
 * One way a vendor order can complete (ORD-12, P-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Orders;

/**
 * Core ships the vendor, customer-confirmation, auto-complete and digital-only triggers; Pro adds OTP,
 * carrier-confirmed delivery and booking-start triggers. Triggers only answer; OrderCompletionPolicy decides.
 */
interface CompletionTrigger {

	/**
	 * Stable identifier recorded with the completion, for example customer_confirmed.
	 */
	public function id(): string;

	/**
	 * Whether this trigger completes the vendor order in its current state.
	 *
	 * @param VendorOrderCompletionState $state Vendor order state.
	 */
	public function fires( VendorOrderCompletionState $state ): bool;
}
