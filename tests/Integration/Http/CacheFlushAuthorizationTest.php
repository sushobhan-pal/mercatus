<?php
/**
 * Only administrators may flush a cache group (TEST-3 row 2, PLAT-5, TM-5).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Kernel\Kernel;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Refused callers can't bump a group's revision; the route refuses everyone until `core.identity`.
 */
final class CacheFlushAuthorizationTest extends WP_UnitTestCase {

	use AdminRouteRefusals;

	public function testRefusesEveryoneAndFlushesNothing(): void {
		$kernel = Kernel::instance();
		$this->assertNotNull( $kernel );
		$cache  = $kernel->container()->get( CachePort::class );
		$before = $cache->revision( 'group:mercatus_stores' );

		$this->assertRefusedToEveryone(
			static function (): WP_REST_Request {
				$request = new WP_REST_Request( 'POST', '/mercatus/v1/system/cache/flush-group' );
				$request->set_param( 'group', 'mercatus_stores' );

				return $request;
			}
		);

		$this->assertSame( $before, $cache->revision( 'group:mercatus_stores' ) );
	}
}
