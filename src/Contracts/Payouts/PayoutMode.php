<?php
/**
 * How a payment integration moves money to vendors (PRO-3).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Payouts;

/**
 * Payout: the marketplace collects, then pays vendors. Split: the customer's payment is split at checkout.
 */
enum PayoutMode: string {
	case Payout = 'payout';
	case Split  = 'split'; // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.splitDeprecatedRemoved -- an enum case, not split().
}
