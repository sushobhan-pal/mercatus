<?php
/**
 * Geohash cells (CAT-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Store;

use InvalidArgumentException;
use Mercatus\Core\Store\Domain\Geohash;
use PHPUnit\Framework\TestCase;

/**
 * Known points give their published geohashes, and points outside the world are refused.
 */
final class GeohashTest extends TestCase {

	/**
	 * Points and their geohashes.
	 *
	 * @return array<string, array{float, float, int, string}>
	 */
	public static function points(): array {
		return array(
			'the reference point'  => array( 57.64911, 10.40744, 11, 'u4pruydqqvj' ),
			'Wikipedia\'s example' => array( 42.6, -5.6, 5, 'ezs42' ),
			'the origin'           => array( 0.0, 0.0, 6, 's00000' ),
			'the far corner'       => array( -90.0, -180.0, 4, '0000' ),
		);
	}

	/**
	 * Encodes a point.
	 *
	 * @dataProvider points
	 * @param float  $latitude  Latitude.
	 * @param float  $longitude Longitude.
	 * @param int    $length    Length.
	 * @param string $hash      Expected geohash.
	 */
	public function testEncodesAPoint( float $latitude, float $longitude, int $length, string $hash ): void {
		$this->assertSame( $hash, Geohash::encode( $latitude, $longitude, $length ) );
	}

	public function testRefusesAPointOutsideTheWorld(): void {
		$this->expectException( InvalidArgumentException::class );

		Geohash::encode( 91.0, 0.0 );
	}
}
