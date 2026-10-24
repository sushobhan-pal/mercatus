<?php
/**
 * Handles domain events (ARCH-8 point 2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Events;

use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * Named in a module manifest's `eventSubscribers`. Handlers run after commit and may run more than once for
 * the same event (the outbox retries), so they must be idempotent. Money never changes here (ARCH-11).
 */
interface EventSubscriber {

	/**
	 * Event names this subscriber handles; "*" for every event.
	 *
	 * @return list<string>
	 */
	public function subscribedEvents(): array;

	/**
	 * Handles an event. Throwing makes the outbox retry it later.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function handle( DomainEvent $event ): void;
}
