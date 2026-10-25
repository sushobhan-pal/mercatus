<?php
/**
 * Geocodes a location after its address changes (CAT-6, STO-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure;

use Mercatus\Core\Contracts\Events\EventSubscriber;
use Mercatus\Core\Shared\Events\DomainEvent;
use Mercatus\Core\Store\Infrastructure\Geocoding\Geocoders;

/**
 * On the bulk lane, so the poller calls the provider and saving an address never waits for it; quick edits to
 * one location coalesce into one lookup. A failure leaves the coordinates empty, and the next change tries
 * again.
 */
final class StoreGeocodingSubscriber implements EventSubscriber {

	/**
	 * Creates the subscriber.
	 *
	 * @param Stores    $stores    Store commands.
	 * @param Geocoders $geocoders The chosen geocoder.
	 */
	public function __construct( private Stores $stores, private Geocoders $geocoders ) {}

	public function subscribedEvents(): array {
		return array( 'store.location.updated' );
	}

	public function handle( DomainEvent $event ): void {
		$location_id = $event->payload['location_id'] ?? null;
		$geocoder    = is_int( $location_id ) ? $this->geocoders->current() : null;
		if ( null !== $geocoder ) {
			$this->stores->geocode( (int) $location_id, $geocoder );
		}
	}
}
