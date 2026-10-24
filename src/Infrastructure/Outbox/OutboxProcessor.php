<?php
/**
 * Handles claimed events (API-3, KER-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use DateInterval;
use Mercatus\Core\Shared\Clock\Clock;
use Throwable;
use UnexpectedValueException;

/**
 * Used both right after commit and by the worker. Bulk events are coalesced first. A handler that throws
 * sends its event back to pending with backoff, until it has failed too often.
 */
final class OutboxProcessor {

	/**
	 * Creates the processor.
	 *
	 * @param OutboxStore        $store      Outbox.
	 * @param EventDispatcher    $dispatcher Subscribers.
	 * @param RetryBackoffPolicy $backoff    Retry timing.
	 * @param BulkEventCoalescer $coalescer  Bulk merging.
	 * @param Clock              $clock      Current time.
	 */
	public function __construct(
		private OutboxStore $store,
		private EventDispatcher $dispatcher,
		private RetryBackoffPolicy $backoff,
		private BulkEventCoalescer $coalescer,
		private Clock $clock,
	) {}

	/**
	 * Handles records claimed with a token.
	 *
	 * @param list<OutboxRecord> $records Claimed records.
	 * @param string             $token   Claim token.
	 */
	public function process( array $records, string $token ): WorkReport {
		$report                   = new WorkReport();
		[ $records, $superseded ] = $this->coalescer->coalesce( $records );
		$this->store->finish( $superseded, $token, $this->clock->now(), 'superseded' );
		$report->superseded = count( $superseded );

		$processed = array();
		foreach ( $records as $record ) {
			try {
				$this->dispatcher->dispatch( $record->event );
				$processed[] = $record->id;
			} catch ( Throwable $error ) {
				$attempts  = $record->attempts + 1;
				$exhausted = $this->backoff->exhausted( $attempts );
				$retry_at  = $exhausted ? null : $this->clock->now()->add( new DateInterval( 'PT' . $this->backoff->delay( $attempts ) . 'S' ) );
				$this->store->release( $record, $token, $attempts, $error instanceof UnexpectedValueException ? 'invalid_event' : $error::class, $retry_at );
				$exhausted ? ++$report->failed : ++$report->retried;
			}
		}
		$this->store->finish( $processed, $token, $this->clock->now() );
		$report->processed = count( $processed );

		return $report;
	}
}
