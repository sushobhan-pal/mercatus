<?php
/**
 * Only administrators and permitted managers may flush a cache group (TEST-3 row 2, PLAT-5, TM-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Kernel\KernelPermissions;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Refused callers can't bump a group's revision; allowed ones do.
 */
final class CacheFlushAuthorizationTest extends WP_UnitTestCase {

	use AdminRouteAccess;

	public function testRefusesEveryoneElseAndFlushesNothing(): void {
		$cache  = $this->cache();
		$before = $cache->revision( 'group:mercatus_stores' );

		$this->assertRefusesAllButPermitted( static fn(): WP_REST_Request => self::flush() );

		$this->assertSame( $before, $cache->revision( 'group:mercatus_stores' ) );
	}

	public function testAllowedCallersBumpTheRevision(): void {
		$cache  = $this->cache();
		$before = $cache->revision( 'group:mercatus_stores' );

		$this->assertAllowsAdministratorsAndPermittedManagers( static fn(): WP_REST_Request => self::flush(), KernelPermissions::CACHE_FLUSH );

		$this->assertGreaterThan( $before, $cache->revision( 'group:mercatus_stores' ) );
	}

	/**
	 * The flush request.
	 */
	private static function flush(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/system/cache/flush-group' );
		$request->set_param( 'group', 'mercatus_stores' );

		return $request;
	}

	/**
	 * The site kernel's cache.
	 */
	private function cache(): CachePort {
		$kernel = Kernel::instance();
		$this->assertNotNull( $kernel );

		return $kernel->container()->get( CachePort::class );
	}
}
