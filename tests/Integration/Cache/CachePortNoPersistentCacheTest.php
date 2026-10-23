<?php
/**
 * The cache adapter without a persistent object cache (TEST-3 row 2, PLAT-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Tests\Contract\Conformance\CachePortConformanceTrait;

/**
 * Everything still works within a request; with no coordination, a stale value is recomputed rather than
 * served.
 *
 * @group cache-none
 */
final class CachePortNoPersistentCacheTest extends CacheIntegrationTestCase {

	use CachePortConformanceTrait;

	protected const MODE = 'none';

	protected function createCachePort(): CachePort {
		wp_cache_flush();

		return $this->newCachePort();
	}

	public function testRunsWithoutAPersistentCache(): void {
		$this->assertFalse( (bool) wp_using_ext_object_cache() );
	}

	public function testRecomputesAStaleValue(): void {
		$cache = $this->newCachePort();
		$calls = 0;
		$build = static function () use ( &$calls ): string {
			++$calls;
			return 'value-' . $calls;
		};
		$cache->remember( 'mercatus_tests', 'key', 60, $build );

		$this->clock->advance( 61 );

		$this->assertSame( 'value-2', $cache->remember( 'mercatus_tests', 'key', 60, $build ) );
	}
}
