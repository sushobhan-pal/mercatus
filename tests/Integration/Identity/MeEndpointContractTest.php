<?php
/**
 * GET /me keeps its contract (IDN-12, ARCH-9).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Kernel\KernelPermissions;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Signed-in actors get their identity, permissions, features and menu in one private response; visitors get
 * 401.
 */
final class MeEndpointContractTest extends WP_UnitTestCase {

	use IdentityTestActors;

	public function tear_down(): void {
		self::grantManagers( array() );
		parent::tear_down();
	}

	public function testRefusesVisitors(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) )->get_status() );
	}

	public function testDescribesACustomer(): void {
		$response = $this->me( 'customer' );
		$data     = $this->data( $response );

		$this->assertSame( 'customer', $data['actor']['type'] );
		$this->assertNull( $data['actor']['vendor_id'] );
		$this->assertSame( array(), $data['permissions'] );
		$this->assertArrayNotHasKey( 'version', $data['features']['modules'][0] );
		$this->assertSame( 'private, no-cache', $response->get_headers()['Cache-Control'] ?? null );
		$this->assertMatchesSchema( $data );
	}

	public function testGivesAnAdministratorEveryPermissionAndTheModuleVersions(): void {
		$data = $this->data( $this->me( 'administrator' ) );

		$this->assertSame( 'administrator', $data['actor']['type'] );
		$this->assertContains( KernelPermissions::MODULES_MANAGE, $data['permissions'] );
		$this->assertArrayHasKey( 'version', $data['features']['modules'][0] );
		$this->assertMatchesSchema( $data );
	}

	public function testListsAManagersSharedPermissions(): void {
		self::grantManagers( array( KernelPermissions::HEALTH_VIEW ) );

		$data = $this->data( $this->me( 'marketplace_manager' ) );

		$this->assertSame( 'marketplace_manager', $data['actor']['type'] );
		$this->assertSame( array( KernelPermissions::HEALTH_VIEW ), $data['permissions'] );
	}

	public function testNamesAnOwnersStore(): void {
		$data = $this->data( $this->me( 'vendor_owner' ) );

		$this->assertSame( 'vendor_owner', $data['actor']['type'] );
		$this->assertSame( 12, $data['actor']['vendor_id'] );
		$this->assertMatchesSchema( $data );
	}

	/**
	 * GET /me as a new user of the given actor type.
	 *
	 * @param string $actor Actor.
	 */
	private function me( string $actor ): WP_REST_Response {
		wp_set_current_user( $this->userAs( $actor ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/me' ) );
		$this->assertSame( 200, $response->get_status() );

		return $response;
	}

	/**
	 * A response's data.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<string, mixed>
	 */
	private function data( WP_REST_Response $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Asserts the data matches the route's schema.
	 *
	 * @param array<string, mixed> $data Response data.
	 */
	private function assertMatchesSchema( array $data ): void {
		$options = rest_get_server()->get_route_options( '/mercatus/v1/me' );
		$this->assertIsArray( $options );
		$this->assertIsCallable( $options['schema'] ?? null );
		$schema = call_user_func( $options['schema'] );
		$this->assertIsArray( $schema );
		$this->assertTrue( rest_validate_value_from_schema( $data, $schema ) );
	}
}
