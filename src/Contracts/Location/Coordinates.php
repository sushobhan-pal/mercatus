<?php
/**
 * A point on the map (CAT-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Location;

use InvalidArgumentException;

/**
 * Latitude and longitude in degrees.
 */
final readonly class Coordinates {

	/**
	 * Creates the point.
	 *
	 * @param float $latitude  Latitude, -90 to 90.
	 * @param float $longitude Longitude, -180 to 180.
	 * @throws InvalidArgumentException When either is out of range.
	 */
	public function __construct( public float $latitude, public float $longitude ) {
		if ( $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
			throw new InvalidArgumentException( 'Coordinates must be within the world.' );
		}
	}
}
