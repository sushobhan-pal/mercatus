<?php
/**
 * Drains the outbox (API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Events\EventLane;
use Mercatus\Core\Shared\Id\Uuid;

/**
 * The poller's and `wp mercatus outbox:work`'s work: the critical lane first, then bulk, in batches, within a
 * total limit.
 */
final class OutboxWorker {

	/**
	 * Events claimed per batch (API-3).
	 */
	public const BATCH = 200;

	public const LEASE_SECONDS = 60;

	/**
	 * Creates the worker.
	 *
	 * @param OutboxStore     $store     Outbox.
	 * @param OutboxProcessor $processor Handling.
	 * @param Clock           $clock     Current time.
	 */
	public function __construct( private OutboxStore $store, private OutboxProcessor $processor, private Clock $clock ) {}

	/**
	 * Handles up to $limit events.
	 *
	 * @param int $limit Most events to handle this round.
	 */
	public function work( int $limit = 500 ): WorkReport {
		$report = new WorkReport();
		$left   = $limit;
		foreach ( array( EventLane::Critical, EventLane::Bulk ) as $lane ) {
			while ( $left > 0 ) {
				$token   = Uuid::v7( $this->clock );
				$records = $this->store->claim( $lane, $token, min( self::BATCH, $left ), $this->clock->now(), self::LEASE_SECONDS );
				if ( array() === $records ) {
					break;
				}
				$report->add( $this->processor->process( $records, $token ) );
				$left -= count( $records );
			}
		}

		return $report;
	}
}
