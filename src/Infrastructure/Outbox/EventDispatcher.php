<?php
/**
 * Hands events to their subscribers (ARCH-8 point 2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Infrastructure\Outbox;

use Closure;
use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * Subscribers of the active modules plus the WordPress bridge, built only when the first event arrives. A
 * subscriber that throws stops the dispatch, so the outbox retries the whole event; subscribers must be
 * idempotent.
 */
final class EventDispatcher {

	/**
	 * Built subscribers.
	 *
	 * @var list<EventSubscriber>|null
	 */
	private ?array $built = null;

	/**
	 * Creates the dispatcher.
	 *
	 * @param Closure(): list<EventSubscriber> $factory Builds the subscribers.
	 */
	public function __construct( private Closure $factory ) {}

	/**
	 * Hands an event to every subscriber that wants it.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function dispatch( DomainEvent $event ): void {
		$this->built ??= ( $this->factory )();
		foreach ( $this->built as $subscriber ) {
			$wanted = $subscriber->subscribedEvents();
			if ( in_array( '*', $wanted, true ) || in_array( $event->name, $wanted, true ) ) {
				$subscriber->handle( $event );
			}
		}
	}
}
