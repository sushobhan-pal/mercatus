<?php
/**
 * Critical events go first (TEST-3 row 3, API-3, KER-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use Mercatus\Core\Shared\Events\EventLane;
use RuntimeException;

/**
 * Critical events are handled right after commit while bulk events wait; the worker drains critical before
 * bulk; a failed handler retries with backoff.
 */
final class CriticalVsBulkLanePriorityTest extends OutboxIntegrationTestCase {

	public function testHandlesCriticalEventsAfterCommitAndLeavesBulkForTheWorker(): void {
		$subscriber        = new RecordingSubscriber();
		$this->subscribers = array( $subscriber );

		[ $bulk, $critical ] = $this->transactions->run(
			fn(): array => array(
				$this->events->publish( 'store.profile.updated', 'store', 1, 1, array(), lane: EventLane::Bulk )->eventId,
				$this->events->publish( 'store.profile.updated', 'store', 2, 1, array() )->eventId,
			)
		);

		$this->assertSame( array( $critical ), $subscriber->received );
		$this->assertSame( 'pending', $this->status( $bulk ) );
	}

	public function testTheWorkerDrainsCriticalBeforeBulk(): void {
		$subscriber        = new RecordingSubscriber();
		$this->subscribers = array( $subscriber );
		$this->store->insertMany( array( $this->event( 'b1', EventLane::Bulk ), $this->event( 'c1' ), $this->event( 'b2', EventLane::Bulk ), $this->event( 'c2' ) ), $this->clock->now() );

		$this->worker->work( 2 );

		$this->assertSame( array( 'test-c1', 'test-c2' ), $subscriber->received );
		$this->assertSame( 'pending', $this->status( 'test-b1' ) );
	}

	public function testAFailedHandlerRetriesLater(): void {
		$attempts          = 0;
		$this->subscribers = array(
			new RecordingSubscriber(
				static function () use ( &$attempts ): void {
					if ( 1 === ++$attempts ) {
						throw new RuntimeException( 'The handler failed once.' );
					}
				}
			),
		);
		$event             = $this->transactions->run( fn(): string => $this->events->publish( 'store.profile.updated', 'store', 3, 1, array() )->eventId );

		$this->assertSame( 'pending', $this->status( $event ) );
		$this->worker->work();
		$this->assertSame( 'pending', $this->status( $event ), 'Not before its backoff.' );

		$this->clock->advance( 31 );
		$this->worker->work();
		$this->assertSame( 'processed', $this->status( $event ) );
	}
}
