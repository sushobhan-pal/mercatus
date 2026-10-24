<?php
/**
 * Geohash cells (CAT-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Store\Domain;

use InvalidArgumentException;

/**
 * The standard geohash: longitude and latitude bits interleaved and written in base 32. Locations store the
 * first CELL characters (about 1.2 km by 0.6 km), so nearby searches can match a cell and its neighbours on
 * an index.
 */
final class Geohash {

	public const CELL = 6;

	private const ALPHABET = '0123456789bcdefghjkmnpqrstuvwxyz';

	/**
	 * A point's geohash.
	 *
	 * @param float $latitude  Latitude, -90 to 90.
	 * @param float $longitude Longitude, -180 to 180.
	 * @param int   $length    Characters, 1 to 12.
	 * @throws InvalidArgumentException When the point or length is out of range.
	 */
	public static function encode( float $latitude, float $longitude, int $length = self::CELL ): string {
		if ( $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $length < 1 || $length > 12 ) {
			throw new InvalidArgumentException( 'A geohash needs a latitude, a longitude and a length within range.' );
		}
		$longitudes = array( -180.0, 180.0 );
		$latitudes  = array( -90.0, 90.0 );
		$hash       = '';
		$char       = 0;
		$bits       = $length * 5;
		for ( $bit = 0; $bit < $bits; $bit++ ) {
			// Even bits halve the longitude range, odd bits the latitude range.
			if ( 0 === $bit % 2 ) {
				$middle     = ( $longitudes[0] + $longitudes[1] ) / 2;
				$upper      = $longitude >= $middle;
				$longitudes = $upper ? array( $middle, $longitudes[1] ) : array( $longitudes[0], $middle );
			} else {
				$middle    = ( $latitudes[0] + $latitudes[1] ) / 2;
				$upper     = $latitude >= $middle;
				$latitudes = $upper ? array( $middle, $latitudes[1] ) : array( $latitudes[0], $middle );
			}
			$char = ( $char << 1 ) | ( $upper ? 1 : 0 );
			if ( 4 === $bit % 5 ) {
				$hash .= self::ALPHABET[ $char ];
				$char  = 0;
			}
		}

		return $hash;
	}
}
