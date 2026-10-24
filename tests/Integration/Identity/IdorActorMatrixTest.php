<?php
/**
 * Every route refuses the actors its metadata excludes (TEST-3 row 4, TEST-2, SEC-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Tests\Security\PolicyMatrixDataProvider;
use Mercatus\Core\Tests\Support\Routes\RouteOperation;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Generated from the registered routes, so a new route is covered the moment it exists. Each case calls the
 * route's own permission callback as that actor.
 */
final class IdorActorMatrixTest extends WP_UnitTestCase {

	use IdentityTestActors;

	public function testEveryRouteRefusesTheActorsItExcludes(): void {
		$cases = PolicyMatrixDataProvider::fromServer( rest_get_server() );
		$this->assertNotEmpty( $cases );

		$needs_resources = array();
		foreach ( $cases as $label => list( $operation, $actor, $status ) ) {
			if ( 404 === $status ) {
				// Another store's resource is a resource policy's decision (SEC-4), proven with real resources.
				$needs_resources[] = $label;
				continue;
			}
			wp_set_current_user( $this->userAs( $actor ) );
			$result = call_user_func( $this->permissionCallback( $operation ), new WP_REST_Request( $operation->method, '/mercatus/v1' . $operation->path ) );

			$this->assertInstanceOf( WP_Error::class, $result, $label );
			$this->assertSame( $status, $result->get_error_data()['status'] ?? null, $label );
		}

		$this->assertSame( array(), $needs_resources, 'Tenant routes exist now: give these cases resources of another store.' );
	}

	/**
	 * The permission callback registered for an operation.
	 *
	 * @param RouteOperation $operation Operation.
	 */
	private function permissionCallback( RouteOperation $operation ): callable {
		foreach ( rest_get_server()->get_routes( 'mercatus/v1' )[ (string) $operation->route ] ?? array() as $handler ) {
			if ( isset( $handler['methods'][ $operation->method ] ) && is_callable( $handler['permission_callback'] ?? null ) ) {
				return $handler['permission_callback'];
			}
		}
		$this->fail( 'No handler for ' . $operation->label() );
	}
}
