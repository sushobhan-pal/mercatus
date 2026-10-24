<?php
/**
 * A cookie-authenticated request without a valid nonce acts as nobody (TEST-3 row 4, SEC-1, TM-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Mercatus relies on WordPress's cookie-and-nonce check (SEC-1) and adds no way around it: without a nonce a
 * signed-in administrator's browser request is anonymous, and a forged nonce is refused outright.
 */
final class CsrfNonceEnforcementTest extends WP_UnitTestCase {

	use IdentityTestActors;

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_rest_auth_cookie'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own flag for a cookie-authenticated request.
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'] );
		parent::tear_down();
	}

	public function testACookieRequestWithoutANonceIsAnonymous(): void {
		wp_set_current_user( $this->userAs( 'administrator' ) );

		$this->assertTrue( rest_get_server()->check_authentication() );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) )->get_status() );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/health' ) )->get_status() );
	}

	public function testAForgedNonceIsRefused(): void {
		wp_set_current_user( $this->userAs( 'administrator' ) );
		$_SERVER['HTTP_X_WP_NONCE'] = 'forged';

		$result = rest_get_server()->check_authentication();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result->get_error_code() );
	}
}
