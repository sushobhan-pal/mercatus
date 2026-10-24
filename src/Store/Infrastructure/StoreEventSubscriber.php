<?php
/**
 * core.store's reactions to vendor events (STO-1, STO-2).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;

/**
 * On the critical lane, right after the vendor's change commits, with the poller as the retry net (API-3):
 * approval creates the store and its default location from the requested name (VEN-5), and suspension,
 * reinstatement and closure move the store's status with the vendor's.
 */
final class StoreEventSubscriber implements EventSubscriber {

	/**
	 * Creates the subscriber.
	 *
	 * @param Stores $stores Store commands.
	 */
	public function __construct( private Stores $stores ) {}

	public function subscribedEvents(): array {
		return array_merge( array( 'vendor.application.approved' ), array_keys( Stores::FOLLOWS ) );
	}

	public function handle( DomainEvent $event ): void {
		$vendor_id = $event->payload['vendor_id'] ?? null;
		if ( ! is_int( $vendor_id ) ) {
			return;
		}
		if ( 'vendor.application.approved' === $event->name ) {
			$this->stores->createForVendor( $vendor_id, is_string( $event->payload['store_name'] ?? null ) ? $event->payload['store_name'] : '' );
			return;
		}
		$status = Stores::FOLLOWS[ $event->name ] ?? null;
		if ( null !== $status ) {
			$this->stores->followVendor( $vendor_id, $status );
		}
	}
}
