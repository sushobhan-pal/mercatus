<?php
/**
 * Warm cached reads run no SQL (TEST-3 row 2, PLAT-3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Cache;

/**
 * A value whose computation queries the database is read again by a new request: from the persistent cache
 * when there is one, from WordPress's in-request cache otherwise. Either way, no query runs.
 */
final class WarmCacheZeroSqlBenchmarkTest extends CacheIntegrationTestCase {

	public function testWarmReadsRunNoQueries(): void {
		global $wpdb;
		$build = static fn(): string => (string) $wpdb->get_var( 'SELECT 1' );
		$this->newCachePort()->remember( 'mercatus_tests', 'counted', 60, $build );

		$before = $wpdb->num_queries;
		$value  = $this->newCachePort()->remember( 'mercatus_tests', 'counted', 60, $build );

		$this->assertSame( '1', $value );
		$this->assertSame( 0, $wpdb->num_queries - $before );
	}
}
