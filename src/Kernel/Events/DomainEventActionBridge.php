<?php
/**
 * Passes committed events to WordPress (ARCH-8 point 2, KER-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Events;

use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * The single bridge: every event, once committed and dispatched, fires `mercatus_event` and
 * `mercatus_event_{name with dots as underscores}`. Listeners run after commit and may see an event again
 * if the outbox retries it.
 */
final class DomainEventActionBridge implements EventSubscriber {

	public function subscribedEvents(): array {
		return array( '*' );
	}

	public function handle( DomainEvent $event ): void {
		/**
		 * Fires for every committed Mercatus event.
		 *
		 * @param DomainEvent $event Event.
		 */
		do_action( 'mercatus_event', $event );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- always mercatus_event_{name}.
		do_action( $event->actionName(), $event );
	}
}
