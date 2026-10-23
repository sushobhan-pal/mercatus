<?php
/**
 * Proves each CI combination runs what it claims (TEST-4): WordPress, WooCommerce with HPOS, Mercatus,
 * the selected database and the selected object cache.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Memcache;
use Redis;
use WP_UnitTestCase;

/**
 * Smoke test for the test environment itself.
 */
final class EnvironmentSmokeTest extends WP_UnitTestCase {

	/**
	 * WooCommerce booted, and Mercatus loaded and declared HPOS compatibility.
	 */
	public function testWooCommerceBootedAndMercatusDeclaredHposCompatibility(): void {
		$this->assertGreaterThan( 0, did_action( 'woocommerce_loaded' ) );

		$hpos = FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains( 'mercatus/mercatus.php', $hpos['compatible'] ?? array() );
	}

	/**
	 * Orders are stored in HPOS tables (PLAT-1).
	 */
	public function testHposIsEnabled(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/**
	 * The database server matches the profile, for example mariadb-11.4.
	 */
	public function testDatabaseMatchesTheSelectedProfile(): void {
		global $wpdb;

		$profile = (string) getenv( 'DB_PROFILE' );
		if ( ! preg_match( '/^(mysql|mariadb)-(\d+\.\d+)$/', $profile, $match ) ) {
			$this->markTestSkipped( 'DB_PROFILE is not set; run through bin/test-integration.sh.' );
		}

		$server = (string) $wpdb->get_var( 'SELECT VERSION()' );

		$this->assertSame( 'mariadb' === $match[1], str_contains( strtolower( $server ), 'mariadb' ), $server );
		$this->assertStringStartsWith( $match[2] . '.', $server );
	}

	/**
	 * A persistent object cache is in use exactly when the combination has one, its server answers,
	 * and values round-trip through the WordPress cache API.
	 */
	public function testObjectCacheMatchesTheSelectedMode(): void {
		$mode = (string) getenv( 'CACHE_MODE' );

		// WordPress 6.6 returns null rather than false when no drop-in is installed.
		$this->assertSame( in_array( $mode, array( 'redis', 'memcached' ), true ), (bool) wp_using_ext_object_cache() );
		$this->assertTrue( wp_cache_set( 'smoke', 'ok', 'mercatus_tests' ) );
		$this->assertSame( 'ok', wp_cache_get( 'smoke', 'mercatus_tests' ) );

		if ( 'redis' === $mode ) {
			$redis = new Redis();
			$this->assertTrue( $redis->connect( 'redis', 6379 ) );
			$this->assertTrue( (bool) $redis->ping() );
		}

		if ( 'memcached' === $mode ) {
			$memcache = new Memcache();
			$this->assertTrue( $memcache->connect( 'memcached', 11211 ) );
			$this->assertNotFalse( $memcache->getVersion() );
		}
	}
}
