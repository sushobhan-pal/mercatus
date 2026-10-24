<?php
/**
 * Shared checks of who may use an admin route (IDN-4, IDN-15).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use Mercatus\Core\Tests\Integration\Identity\IdentityTestActors;
use WP_REST_Request;

/**
 * Visitors get 401; customers, vendors and managers without the route's permission get 403; administrators
 * and managers holding it get through.
 */
trait AdminRouteAccess {

	use IdentityTestActors;

	public function tear_down(): void {
		self::grantManagers( array() );
		parent::tear_down();
	}

	/**
	 * Runs a request as a new user of an actor type and returns its status and error code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $actor   Actor type.
	 * @return array{int, string}
	 */
	private function callAs( WP_REST_Request $request, string $actor ): array {
		wp_set_current_user( $this->userAs( $actor ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		return array( $response->get_status(), is_array( $data ) && is_string( $data['code'] ?? null ) ? $data['code'] : '' );
	}

	/**
	 * Asserts who is refused.
	 *
	 * @param callable(): WP_REST_Request $request Builds the request.
	 */
	private function assertRefusesAllButPermitted( callable $request ): void {
		$this->assertSame( array( 401, 'mercatus_unauthenticated' ), $this->callAs( $request(), 'anonymous' ) );
		foreach ( array( 'customer', 'vendor_owner', 'marketplace_manager' ) as $actor ) {
			$this->assertSame( array( 403, 'mercatus_forbidden' ), $this->callAs( $request(), $actor ), $actor );
		}
	}

	/**
	 * Asserts that administrators, and managers holding the permission, get through.
	 *
	 * @param callable(): WP_REST_Request $request    Builds the request.
	 * @param string                      $permission The route's permission.
	 */
	private function assertAllowsAdministratorsAndPermittedManagers( callable $request, string $permission ): void {
		$this->assertSame( 200, $this->callAs( $request(), 'administrator' )[0] );
		self::grantManagers( array( $permission ) );
		$this->assertSame( 200, $this->callAs( $request(), 'marketplace_manager' )[0] );
	}
}
