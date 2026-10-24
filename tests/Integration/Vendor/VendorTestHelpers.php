<?php
/**
 * Shared steps for the vendor tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use Mercatus\Core\Tests\Integration\Identity\IdentityTestActors;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registering, deciding and reading through the real endpoints.
 */
trait VendorTestHelpers {

	use IdentityTestActors;

	/**
	 * Registers through the endpoint as the current user, or as a visitor.
	 *
	 * @param array<string, mixed> $fields Fields.
	 */
	private function registerVendor( array $fields = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/vendor-registrations' );
		$request->set_body_params( $fields + array( 'store_name' => 'Corner Books' ) );

		return rest_do_request( $request );
	}

	/**
	 * Calls an endpoint as an administrator, then restores the current user.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $params Parameters.
	 */
	private function asAdministrator( string $method, string $route, array $params = array() ): WP_REST_Response {
		$current = get_current_user_id();
		wp_set_current_user( $this->userAs( 'administrator' ) );
		$request = new WP_REST_Request( $method, '/mercatus/v1' . $route );
		'GET' === $method ? $request->set_query_params( $params ) : $request->set_body_params( $params );
		$response = rest_do_request( $request );
		wp_set_current_user( $current );

		return $response;
	}

	/**
	 * A response's data, after checking its status.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param int              $status   Expected status.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response, int $status ): array {
		$data = $response->get_data();
		$this->assertSame( $status, $response->get_status(), (string) wp_json_encode( $data ) );
		$this->assertIsArray( $data );

		return $data;
	}
}
