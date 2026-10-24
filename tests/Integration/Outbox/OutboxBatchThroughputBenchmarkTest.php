<?php
/**
 * 1,000 events in under 2 seconds (TEST-3 row 3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use Mercatus\Core\Kernel\Events\DomainEventActionBridge;
use Mercatus\Core\Shared\Events\EventLane;

/**
 * Publishing 1,000 events in one transaction and handling them through the worker, with the WordPress bridge
 * attached, takes under two seconds.
 */
final class OutboxBatchThroughputBenchmarkTest extends OutboxIntegrationTestCase {

	public function testHandlesAThousandEventsInUnderTwoSeconds(): void {
		$this->subscribers = array( new DomainEventActionBridge() );
		$started           = microtime( true );

		$this->transactions->run(
			function (): void {
				foreach ( range( 1, 1000 ) as $n ) {
					$this->events->publish( 'store.profile.updated', 'store', $n, 1, array( 'n' => $n ), lane: EventLane::Bulk );
				}
			}
		);
		$report = $this->worker->work( 1000 );

		$elapsed = microtime( true ) - $started;
		$this->assertSame( 1000, $report->processed );
		$this->assertLessThan( 2.0, $elapsed, sprintf( 'Took %.2f s.', $elapsed ) );
	}
}
