<?php
/**
 * Outbox lanes (API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Shared\Events;

/**
 * Critical events are handled right after commit, with the poller as the retry net; bulk events wait for the
 * poller and may be coalesced.
 */
enum EventLane: string {
	case Critical = 'critical';
	case Bulk     = 'bulk';
}
