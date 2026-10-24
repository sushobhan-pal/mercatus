<?php
/**
 * Committed events reach WordPress through one bridge (TEST-3 row 3, ARCH-8).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use Mercatus\Core\Kernel\Events\DomainEventActionBridge;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Shared\Events\EventLane;
use RuntimeException;

/**
 * Both actions fire once, only after commit, never for a rolled-back event; bulk events fire when the
 * worker handles them.
 */
final class DomainEventActionBridgeTest extends OutboxIntegrationTestCase {

	/**
	 * Events seen by mercatus_event and by the named action.
	 *
	 * @var array{generic: list<string>, named: list<string>}
	 */
	private array $fired = array(
		'generic' => array(),
		'named'   => array(),
	);

	public function set_up(): void {
		parent::set_up();
		$this->subscribers = array( new DomainEventActionBridge() );
		add_action( 'mercatus_event', array( $this, 'onGeneric' ) );
		add_action( 'mercatus_event_store_profile_updated', array( $this, 'onNamed' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_event', array( $this, 'onGeneric' ) );
		remove_action( 'mercatus_event_store_profile_updated', array( $this, 'onNamed' ) );
		parent::tear_down();
	}

	public function testFiresBothActionsOnceAfterCommit(): void {
		$event = $this->transactions->run(
			function (): string {
				$id = $this->events->publish( 'store.profile.updated', 'store', 7, 2, array( 'name' => 'Shop' ) )->eventId;
				$this->assertSame( array(), $this->fired['generic'], 'Nothing fires before commit.' );

				return $id;
			}
		);

		$this->assertSame( array( $event ), $this->fired['generic'] );
		$this->assertSame( array( $event ), $this->fired['named'] );
	}

	public function testNeverFiresForARolledBackEvent(): void {
		$this->swallow(
			fn() => $this->transactions->run(
				function (): void {
					$this->events->publish( 'store.profile.updated', 'store', 7, 2, array() );
					throw new RuntimeException( 'Rolled back.' );
				}
			)
		);

		$this->assertSame( array(), $this->fired['generic'] );
	}

	public function testFiresBulkEventsWhenTheWorkerHandlesThem(): void {
		$event = $this->transactions->run( fn(): string => $this->events->publish( 'store.profile.updated', 'store', 7, 2, array(), lane: EventLane::Bulk )->eventId );
		$this->assertSame( array(), $this->fired['generic'] );

		$this->worker->work();

		$this->assertSame( array( $event ), $this->fired['named'] );
	}

	/**
	 * Records mercatus_event.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function onGeneric( DomainEvent $event ): void {
		$this->fired['generic'][] = $event->eventId;
	}

	/**
	 * Records mercatus_event_store_profile_updated.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function onNamed( DomainEvent $event ): void {
		$this->fired['named'][] = $event->eventId;
	}
}
