<?php
/**
 * Shared steps for the store tests.
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Store;

use Mercatus\Core\Tests\Integration\Vendor\VendorTestHelpers;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Vendors approved through the real endpoints, so their stores come from the approval event (STO-1), and calls
 * as the current user.
 */
trait StoreTestHelpers {

	use VendorTestHelpers;

	/**
	 * A customer who applied and was approved, signed in as them.
	 *
	 * @param string $store_name Store name they applied with.
	 * @return array{user_id: int, vendor_id: int, store_id: int, slug: string}
	 */
	private function vendorWithStore( string $store_name = 'Rare Maps' ): array {
		$user_id = $this->userAs( 'customer' );
		wp_set_current_user( $user_id );
		$id = (int) $this->data( $this->registerVendor( array( 'store_name' => $store_name ) ), 201 )['application']['id'];
		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/approve' ), 200 );
		$store = $this->data( $this->call( 'GET', '/me/store' ), 200 );

		return array(
			'user_id'   => $user_id,
			'vendor_id' => (int) $store['vendor_id'],
			'store_id'  => (int) $store['id'],
			'slug'      => (string) $store['slug'],
		);
	}

	/**
	 * Opens the current vendor's store (STO-13).
	 */
	private function openMyStore(): void {
		$this->data( $this->call( 'PATCH', '/me/store', array( 'visibility' => 'public' ), $this->etag( $this->call( 'GET', '/me/store' ) ) ), 200 );
	}

	/**
	 * Calls an endpoint as the current user.
	 *
	 * @param string               $method   Method.
	 * @param string               $route    Route below the namespace.
	 * @param array<string, mixed> $params   Query (GET) or body parameters.
	 * @param string|null          $if_match If-Match header.
	 */
	private function call( string $method, string $route, array $params = array(), ?string $if_match = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mercatus/v1' . $route );
		'GET' === $method ? $request->set_query_params( $params ) : $request->set_body_params( $params );
		if ( null !== $if_match ) {
			$request->set_header( 'If-Match', $if_match );
		}

		return rest_do_request( $request );
	}

	/**
	 * A response's ETag.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function etag( WP_REST_Response $response ): string {
		$etag = $response->get_headers()['ETag'] ?? '';
		$this->assertIsString( $etag );
		$this->assertNotSame( '', $etag );

		return $etag;
	}
}
