<?php
/**
 * Registering, listing and revoking API clients and app sign-ins (IDN-17, IDN-18).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Administrators register clients and issue integration passwords, shown once; users see and revoke their
 * own sign-ins; revoking an integration deletes its passwords; invalid values are refused with the field.
 */
final class ApiClientManagementTest extends WP_UnitTestCase {

	use IdentityTestActors;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( $this->userAs( 'administrator' ) );
	}

	public function testAnIntegrationGetsPasswordsItsUserCanSeeAndRevoke(): void {
		$user   = $this->userAs( 'customer' );
		$client = $this->data( $this->create( 'Warehouse sync', 'integration', array( 'read', 'write' ), $user ), 201 );
		$this->assertSame( array( 'integration', $user, 'active' ), array( $client['kind'], $client['user_id'], $client['status'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $client['app_id'] );

		$password = $this->data( rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/api-clients/' . $client['id'] . '/passwords' ) ), 201 );
		$this->assertNotEmpty( $password['password'] );

		wp_set_current_user( $user );
		$mine = $this->data( rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me/app-passwords' ) ), 200 );
		$this->assertSame( array( 'Warehouse sync' ), array_column( $mine['items'], 'client' ) );

		$this->data( rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/me/app-passwords/' . $password['uuid'] . '/revoke' ) ), 200 );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $user ) );
	}

	public function testRevokingAnIntegrationDeletesItsPasswords(): void {
		$user   = $this->userAs( 'customer' );
		$client = $this->data( $this->create( 'Accounting', 'integration', array( 'read' ), $user ), 201 );
		rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/api-clients/' . $client['id'] . '/passwords' ) );

		$revoked = $this->data( rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/api-clients/' . $client['id'] . '/revoke' ) ), 200 );

		$this->assertSame( 'revoked', $revoked['status'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $user ) );
	}

	public function testAnAppGetsNoPasswordsHere(): void {
		$client = $this->data( $this->create( 'Mobile app', 'app', array( 'read' ) ), 201 );

		$refusal = $this->data( rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/api-clients/' . $client['id'] . '/passwords' ) ), 409 );
		$this->assertSame( 'mercatus_api_client_not_integration', $refusal['code'] );
	}

	/**
	 * Values the endpoint refuses, and the field each refusal names.
	 *
	 * @return array<string, array{string, list<string>, int|null, string}>
	 */
	public static function invalid(): array {
		return array(
			'unknown scope'                => array( 'app', array( 'read', 'everything.allowed' ), null, 'scopes' ),
			'integration without its user' => array( 'integration', array( 'read' ), null, 'user_id' ),
			'integration with no user'     => array( 'integration', array( 'read' ), 999999, 'user_id' ),
		);
	}

	/**
	 * Refuses invalid values with the field.
	 *
	 * @dataProvider invalid
	 * @param string       $kind   Kind.
	 * @param list<string> $scopes Scopes.
	 * @param int|null     $user   User ID.
	 * @param string       $field  Field the refusal names.
	 */
	public function testRefusesInvalidValues( string $kind, array $scopes, ?int $user, string $field ): void {
		$refusal = $this->data( $this->create( 'Probe', $kind, $scopes, $user ), 422 );

		$this->assertSame( array( 'mercatus_api_client_invalid', $field ), array( $refusal['code'], ( (array) $refusal['details'] )['field'] ?? null ) );
	}

	public function testRefusesAnInvalidAddressRange(): void {
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/api-clients' );
		$request->set_body_params(
			array(
				'name'          => 'Probe',
				'kind'          => 'app',
				'scopes'        => array( 'read' ),
				'ip_allow_list' => array( '10.0.0.0/99' ),
			)
		);

		$this->assertSame( 'ip_allow_list', ( (array) $this->data( rest_do_request( $request ), 422 )['details'] )['field'] ?? null );
	}

	public function testListsClientsInPagesAndByStatus(): void {
		$ids = array();
		foreach ( array( 'One', 'Two', 'Three' ) as $name ) {
			$ids[] = (int) $this->data( $this->create( $name, 'app', array( 'read' ) ), 201 )['id'];
		}
		rest_do_request( new WP_REST_Request( 'POST', '/mercatus/v1/api-clients/' . $ids[2] . '/revoke' ) );

		$first  = $this->data( $this->list( array( 'per_page' => 2 ) ), 200 );
		$second = $this->data(
			$this->list(
				array(
					'per_page' => 2,
					'cursor'   => $first['next_cursor'],
				)
			),
			200
		);
		$this->assertSame( array( true, false ), array( $first['has_more'], $second['has_more'] ) );
		$this->assertSame( $ids, array_merge( array_column( $first['items'], 'id' ), array_column( $second['items'], 'id' ) ) );

		$revoked = $this->data( $this->list( array( 'status' => 'revoked' ) ), 200 );
		$this->assertSame( array( $ids[2] ), array_column( $revoked['items'], 'id' ) );
	}

	/**
	 * POST /api-clients.
	 *
	 * @param string       $name   Name.
	 * @param string       $kind   Kind.
	 * @param list<string> $scopes Scopes.
	 * @param int|null     $user   User ID.
	 */
	private function create( string $name, string $kind, array $scopes, ?int $user = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mercatus/v1/api-clients' );
		$request->set_body_params(
			array_filter(
				array(
					'name'    => $name,
					'kind'    => $kind,
					'scopes'  => $scopes,
					'user_id' => $user,
				),
				static fn( mixed $value ): bool => null !== $value
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * GET /api-clients.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	private function list( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/mercatus/v1/api-clients' );
		$request->set_query_params( $params );

		return rest_do_request( $request );
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
