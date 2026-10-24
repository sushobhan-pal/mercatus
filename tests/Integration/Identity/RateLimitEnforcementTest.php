<?php
/**
 * Routes refuse floods when a persistent cache exists (PLAT-8, IDN-10, TM-37).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Identity\Application\IdentitySettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * With Redis or Memcached a visitor past their limit gets 429 with Retry-After, and every response carries
 * the RateLimit headers. Without a persistent cache nothing is limited (AR-1). Administrators never are.
 */
final class RateLimitEnforcementTest extends WP_UnitTestCase {

	use IdentityTestActors;

	public function set_up(): void {
		parent::set_up();
		self::kernel()->settings()->set( IdentitySettings::RATE_LIMIT_VISITOR_READS, 2 );
	}

	public function tear_down(): void {
		self::kernel()->settings()->set( IdentitySettings::RATE_LIMIT_VISITOR_READS, 120 );
		parent::tear_down();
	}

	public function testAVisitorPastTheirLimitIsRefusedOnlyWithAPersistentCache(): void {
		wp_set_current_user( 0 );
		$responses = array_map( static fn(): WP_REST_Response => rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/features' ) ), range( 1, 3 ) );

		if ( ! wp_using_ext_object_cache() ) {
			$this->assertSame( array( 200, 200, 200 ), array_map( static fn( WP_REST_Response $response ): int => $response->get_status(), $responses ) );
			$this->assertArrayNotHasKey( 'RateLimit-Limit', $responses[2]->get_headers() );
			return;
		}
		$this->assertSame( array( 200, 200, 429 ), array_map( static fn( WP_REST_Response $response ): int => $response->get_status(), $responses ) );
		$headers = $responses[2]->get_headers();
		$this->assertSame( array( '2', '0' ), array( $headers['RateLimit-Limit'] ?? null, $headers['RateLimit-Remaining'] ?? null ) );
		$this->assertSame( $headers['RateLimit-Reset'] ?? null, $headers['Retry-After'] ?? null );
		$data = $responses[2]->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'mercatus_rate_limited', $data['code'] );
	}

	public function testAdministratorsAreNotLimited(): void {
		self::kernel()->settings()->set( IdentitySettings::RATE_LIMIT_USER_READS, 1 );
		wp_set_current_user( $this->userAs( 'administrator' ) );

		foreach ( range( 1, 3 ) as $attempt ) {
			$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/features' ) )->get_status(), (string) $attempt );
		}
		self::kernel()->settings()->set( IdentitySettings::RATE_LIMIT_USER_READS, 600 );
	}
}
