<?php
/**
 * Repeated bulk events collapse into the newest (TEST-3 row 3, KER-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Outbox;

use DateTimeImmutable;
use Mercatus\Core\Infrastructure\Outbox\BulkEventCoalescer;
use Mercatus\Core\Infrastructure\Outbox\OutboxRecord;
use Mercatus\Core\Shared\Actor\ActorRef;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use PHPUnit\Framework\TestCase;

/**
 * The newest per key is handled, older ones are superseded, and keyless events all go through.
 */
final class BulkEventCoalescerTest extends TestCase {

	public function testKeepsTheNewestEventPerKey(): void {
		[ $handle, $superseded ] = ( new BulkEventCoalescer() )->coalesce(
			array(
				$this->record( 5, 'product:1' ),
				$this->record( 2, 'product:1' ),
				$this->record( 3, null ),
				$this->record( 9, 'product:2' ),
				$this->record( 7, 'product:1' ),
				$this->record( 4, null ),
			)
		);

		$this->assertSame( array( 3, 4, 7, 9 ), array_map( static fn( OutboxRecord $record ): int => $record->id, $handle ) );
		$this->assertSame( array( 2, 5 ), $superseded );
	}

	public function testHandlesAnEmptyBatch(): void {
		$this->assertSame( array( array(), array() ), ( new BulkEventCoalescer() )->coalesce( array() ) );
	}

	/**
	 * A claimed bulk record.
	 *
	 * @param int         $id  Row ID.
	 * @param string|null $key Coalesce key.
	 */
	private function record( int $id, ?string $key ): OutboxRecord {
		$event = new DomainEvent( 'catalog.offer.reindexed', 'uuid-' . $id, new DateTimeImmutable(), 'offer', (string) $id, 1, ActorRef::system(), 'req_x', array(), 1, EventLane::Bulk, $key );

		return new OutboxRecord( $id, $event, 0 );
	}
}
