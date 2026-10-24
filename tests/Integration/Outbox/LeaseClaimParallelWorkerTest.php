<?php
/**
 * Parallel workers never take the same event (TEST-3 row 3, KER-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use Mercatus\Core\Infrastructure\Outbox\OutboxRecord;
use Mercatus\Core\Shared\Events\EventLane;

/**
 * Claims are disjoint while leases last; a lease that ran out (its worker died) can be claimed again, and
 * the dead worker can no longer finish those events.
 */
final class LeaseClaimParallelWorkerTest extends OutboxIntegrationTestCase {

	public function testClaimsAreDisjointUntilTheLeaseRunsOut(): void {
		$events = array();
		foreach ( range( 1, 8 ) as $n ) {
			$events[] = $this->event( 'lease-' . $n );
		}
		$this->store->insertMany( $events, $this->clock->now() );

		$first  = $this->ids( $this->store->claim( EventLane::Critical, 'worker-a', 5, $this->clock->now(), 60 ) );
		$second = $this->ids( $this->store->claim( EventLane::Critical, 'worker-b', 5, $this->clock->now(), 60 ) );
		$third  = $this->store->claim( EventLane::Critical, 'worker-c', 5, $this->clock->now(), 60 );

		$this->assertCount( 5, $first );
		$this->assertCount( 3, $second );
		$this->assertSame( array(), array_intersect( $first, $second ) );
		$this->assertSame( array(), $third );

		$this->clock->advance( 61 );
		$reclaimed = $this->ids( $this->store->claim( EventLane::Critical, 'worker-d', 10, $this->clock->now(), 60 ) );
		$this->assertCount( 8, $reclaimed );

		$this->store->finish( $first, 'worker-a', $this->clock->now() );
		$this->assertSame( 'pending', $this->status( 'test-lease-1' ), 'A dead worker can no longer finish its events.' );
		$this->store->finish( $reclaimed, 'worker-d', $this->clock->now() );
		$this->assertSame( 'processed', $this->status( 'test-lease-1' ) );
	}

	/**
	 * Row IDs of records.
	 *
	 * @param list<OutboxRecord> $records Records.
	 * @return list<int>
	 */
	private function ids( array $records ): array {
		return array_map( static fn( OutboxRecord $record ): int => $record->id, $records );
	}
}
