<?php
/**
 * State of a payout at the provider (PRO-3, PRO-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Payouts;

/**
 * Pending until the provider confirms the money moved or reports a failure.
 */
enum PayoutStatus: string {
	case Pending = 'pending';
	case Paid    = 'paid';
	case Failed  = 'failed';
}
