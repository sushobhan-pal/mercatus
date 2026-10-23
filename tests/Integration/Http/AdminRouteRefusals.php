<?php
/**
 * Shared checks that admin routes refuse until core.identity.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use WP_REST_Request;

/**
 * Anonymous callers get 401; signed-in callers get 403, administrators included, until `core.identity`
 * plugs in its policies (KER-11). `core.identity` adds the cases where an administrator is allowed.
 */
trait AdminRouteRefusals {

	/**
	 * Runs a request as a user with a role, or as nobody.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string|null     $role    WordPress role; null for a visitor.
	 * @return array{int, string}
	 */
	private function callAs( WP_REST_Request $request, ?string $role ): array {
		$user_id = 0;
		if ( null !== $role ) {
			$user_id = self::factory()->user->create( array( 'role' => $role ) );
			$this->assertIsInt( $user_id );
		}
		wp_set_current_user( $user_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		return array( $response->get_status(), is_array( $data ) && is_string( $data['code'] ?? null ) ? $data['code'] : '' );
	}

	/**
	 * Asserts the refusals for one request.
	 *
	 * @param callable(): WP_REST_Request $request Builds the request.
	 */
	private function assertRefusedToEveryone( callable $request ): void {
		$this->assertSame( array( 401, 'mercatus_unauthenticated' ), $this->callAs( $request(), null ) );
		$this->assertSame( array( 403, 'mercatus_forbidden' ), $this->callAs( $request(), 'subscriber' ) );
		$this->assertSame( array( 403, 'mercatus_forbidden' ), $this->callAs( $request(), 'administrator' ) );
	}
}
