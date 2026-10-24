<?php
/**
 * ETags come from the database version, and changes need the current one (TEST-3 row 2, PLAT-9, G-SEC-3, TM-18).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * An item's ETag is its version and a list's hashes its page; a PATCH needs If-Match with the current
 * version: none is 428, a stale one 409. A known ETag gets a 304.
 */
final class EtagFromVersionTest extends WP_UnitTestCase {

	use IdentityTestActors;

	private int $id = 0;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( $this->userAs( 'administrator' ) );
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/api-clients' );
		$request->set_body_params(
			array(
				'name'   => 'Mobile app',
				'kind'   => 'app',
				'scopes' => array( 'read' ),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( array( 201, '"v1"' ), array( $response->get_status(), $response->get_headers()['ETag'] ?? null ) );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->id = (int) $data['id'];
	}

	public function testAChangeNeedsTheCurrentVersion(): void {
		$this->assertSame( 428, $this->patch( null, 'No If-Match' )->get_status() );
		$this->assertSame( 409, $this->patch( '"v7"', 'Stale' )->get_status() );

		$changed = $this->patch( '"v1"', 'Renamed' );
		$this->assertSame( array( 200, '"v2"' ), array( $changed->get_status(), $changed->get_headers()['ETag'] ?? null ) );
		$this->assertSame( 'Renamed', ( (array) $changed->get_data() )['name'] ?? null );

		$this->assertSame( 409, $this->patch( '"v1"', 'Lost update' )->get_status(), 'A second editor with the old version is refused.' );
	}

	public function testAListsETagFollowsItsItemsVersions(): void {
		$first = $this->list();
		$etag  = $first->get_headers()['ETag'] ?? null;
		$this->assertIsString( $etag );

		$again = new WP_REST_Request( 'GET', '/mercatus/v1/api-clients' );
		$again->set_header( 'If-None-Match', $etag );
		$this->assertSame( 304, rest_do_request( $again )->get_status() );

		$this->patch( '"v1"', 'Renamed' );
		$this->assertNotSame( $etag, $this->list()->get_headers()['ETag'] ?? null );
	}

	/**
	 * PATCHes the client's name.
	 *
	 * @param string|null $if_match If-Match header.
	 * @param string      $name     New name.
	 */
	private function patch( ?string $if_match, string $name ): WP_REST_Response {
		$request = new WP_REST_Request( 'PATCH', '/mercatus/v1/api-clients/' . $this->id );
		if ( null !== $if_match ) {
			$request->set_header( 'If-Match', $if_match );
		}
		$request->set_body_params( array( 'name' => $name ) );

		return rest_do_request( $request );
	}

	/**
	 * GET /api-clients.
	 */
	private function list(): WP_REST_Response {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/api-clients' ) );
		$this->assertSame( 200, $response->get_status() );

		return $response;
	}
}
