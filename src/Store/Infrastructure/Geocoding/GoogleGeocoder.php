<?php
/**
 * Google's Geocoding API (STO-14).
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
 * With the administrator's key, stored encrypted (SEC-6). The country narrows the search.
 */
final class GoogleGeocoder implements Geocoder {

	public const HOST = 'maps.googleapis.com';

	/**
	 * Creates the adapter.
	 *
	 * @param OutboundHttp $http Outbound HTTP.
	 * @param string       $key  API key.
	 */
	public function __construct( private OutboundHttp $http, private string $key ) {
		$this->http->allow( self::HOST );
	}

	public function geocode( array $address ): ?Coordinates {
		$text = implode( ', ', array_filter( array( $address['address_1'] ?? '', $address['address_2'] ?? '', $address['city'] ?? '', $address['state'] ?? '', $address['postcode'] ?? '' ) ) );
		if ( '' === $this->key || '' === $text ) {
			return null;
		}
		$query    = array_filter(
			array(
				'address'    => $text,
				'components' => '' === ( $address['country'] ?? '' ) ? '' : 'country:' . $address['country'],
				'key'        => $this->key,
			)
		);
		$response = $this->http->get( 'https://' . self::HOST . '/maps/api/geocode/json?' . http_build_query( $query ) );
		$data     = null === $response || 200 !== $response['status'] ? null : json_decode( $response['body'], true );
		$location = is_array( $data ) && 'OK' === ( $data['status'] ?? '' ) ? ( $data['results'][0]['geometry']['location'] ?? null ) : null;
		if ( ! is_array( $location ) || ! is_numeric( $location['lat'] ?? null ) || ! is_numeric( $location['lng'] ?? null ) ) {
			return null;
		}
		try {
			return new Coordinates( (float) $location['lat'], (float) $location['lng'] );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}
}
