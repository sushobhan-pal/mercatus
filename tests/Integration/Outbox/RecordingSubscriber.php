<?php
/**
 * A subscriber that records what it receives.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Outbox;

use Closure;
use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * Records event IDs in order, and can be told to fail.
 */
final class RecordingSubscriber implements EventSubscriber {

	/**
	 * Event IDs received, in order.
	 *
	 * @var list<string>
	 */
	public array $received = array();

	/**
	 * Creates the subscriber.
	 *
	 * @param (Closure(DomainEvent): void)|null $onEvent Runs for each event; may throw.
	 */
	public function __construct( private ?Closure $onEvent = null ) {}

	public function subscribedEvents(): array {
		return array( '*' );
	}

	public function handle( DomainEvent $event ): void {
		if ( null !== $this->onEvent ) {
			( $this->onEvent )( $event );
		}
		$this->received[] = $event->eventId;
	}
}
