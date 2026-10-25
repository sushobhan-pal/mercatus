<?php
/**
 * OpenStreetMap's Nominatim (STO-14).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Infrastructure\Geocoding;

use InvalidArgumentException;
use Mercatus\Core\Contracts\Location\Coordinates;
use Mercatus\Core\Contracts\Location\Geocoder;
use Mercatus\Core\Infrastructure\Http\OutboundHttp;

/**
 * A structured search, no key. Mercatus asks only when an address changes, which stays within Nominatim's
 * usage policy for a marketplace's vendors; the request names the site in its User-Agent, as the policy asks.
 */
final class NominatimGeocoder implements Geocoder {

	public const HOST = 'nominatim.openstreetmap.org';

	/**
	 * Creates the adapter.
	 *
	 * @param OutboundHttp $http Outbound HTTP.
	 */
	public function __construct( private OutboundHttp $http ) {
		$this->http->allow( self::HOST );
	}

	public function geocode( array $address ): ?Coordinates {
		$query    = array_filter(
			array(
				'format'     => 'jsonv2',
				'limit'      => '1',
				'street'     => trim( ( $address['address_1'] ?? '' ) . ' ' . ( $address['address_2'] ?? '' ) ),
				'city'       => $address['city'] ?? '',
				'state'      => $address['state'] ?? '',
				'postalcode' => $address['postcode'] ?? '',
				'country'    => $address['country'] ?? '',
			)
		);
		$response = $this->http->get( 'https://' . self::HOST . '/search?' . http_build_query( $query ) );
		$results  = null === $response || 200 !== $response['status'] ? null : json_decode( $response['body'], true );
		$first    = is_array( $results ) ? ( $results[0] ?? null ) : null;
		if ( ! is_array( $first ) || ! is_numeric( $first['lat'] ?? null ) || ! is_numeric( $first['lon'] ?? null ) ) {
			return null;
		}
		try {
			return new Coordinates( (float) $first['lat'], (float) $first['lon'] );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}
}
