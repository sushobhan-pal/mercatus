<?php
/**
 * getMany() reads many keys in one round trip (TEST-3 row 2, PLAT-3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

use Mercatus\Core\Tests\Fixtures\Cache\RecordingObjectCache;

/**
 * Hits and misses come back correctly from the backend; where the drop-in supports multi-get, it takes one
 * get_multiple call; and the per-request memory answers repeats without the backend.
 */
final class BatchMultiGetIntegrationTest extends CacheIntegrationTestCase {

	public function testReadsManyKeysInOneRoundTrip(): void {
		$this->newCachePort()->setMany(
			'mercatus_tests',
			array(
				'a' => 1,
				'b' => false,
				'c' => array( 'x' ),
			),
			60
		);
		$cache = $this->newCachePort();
		$cache->revision( 'group:mercatus_tests' );

		$recorder = $this->record();
		$found    = $cache->getMany( 'mercatus_tests', array( 'a', 'b', 'c', 'missing' ) );
		$again    = $cache->getMany( 'mercatus_tests', array( 'a', 'b', 'c' ) );
		$this->restore( $recorder );

		ksort( $found );
		$this->assertSame(
			array(
				'a' => 1,
				'b' => false,
				'c' => array( 'x' ),
			),
			$found
		);
		$this->assertSame( 3, count( $again ) );
		if ( wp_cache_supports( 'get_multiple' ) ) {
			$this->assertSame( 1, $recorder->count( 'get_multiple', 'mercatus_tests' ) );
			$this->assertSame( 0, $recorder->count( 'get', 'mercatus_tests' ) );
		} else {
			$this->assertSame( 4, $recorder->count( 'get', 'mercatus_tests' ) );
		}
	}

	/**
	 * Puts a recorder in front of the object cache.
	 */
	private function record(): RecordingObjectCache {
		global $wp_object_cache;
		$recorder                   = new RecordingObjectCache( $wp_object_cache );
		$GLOBALS['wp_object_cache'] = $recorder; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only proxy, restored right after.

		return $recorder;
	}

	/**
	 * Puts the real object cache back.
	 *
	 * @param RecordingObjectCache $recorder Recorder.
	 */
	private function restore( RecordingObjectCache $recorder ): void {
		$GLOBALS['wp_object_cache'] = $recorder->inner; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the real cache.
	}
}
