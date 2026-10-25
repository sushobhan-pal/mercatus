<?php
/**
 * Turns an address into coordinates (CAT-6, STO-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Location;

/**
 * Called only when a location's address changes, never during cart or checkout (CAT-6). Core ships
 * Nominatim and Google adapters; Pro or a site may add its own. An adapter talks to its provider through
 * Mercatus's outbound allow-list (SEC-7).
 */
interface Geocoder {

	/**
	 * The address's coordinates, or null when the provider can't place it or can't be reached.
	 *
	 * @param array<string, string> $address address_1, address_2, city, state, postcode and country (ISO 3166-1 alpha-2).
	 */
	public function geocode( array $address ): ?Coordinates;
}
