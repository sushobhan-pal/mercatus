<?php
/**
 * Every route refuses the actors its metadata excludes (TEST-3 row 4, TEST-2, SEC-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Identity;

use Mercatus\Core\Tests\Integration\Catalog\CatalogTestHelpers;
use Mercatus\Core\Tests\Security\PolicyMatrixDataProvider;
use Mercatus\Core\Tests\Support\Routes\RouteOperation;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Generated from the registered routes, so a new route is covered the moment it exists. Each case calls the
 * route's own permission callback as that actor; another store's resource, a resource policy's decision, is
 * requested for real by a vendor of another store, who must be told it doesn't exist.
 */
final class IdorActorMatrixTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testEveryRouteRefusesTheActorsItExcludes(): void {
		$cases = PolicyMatrixDataProvider::fromServer( rest_get_server() );
		$this->assertNotEmpty( $cases );

		$resources = null;
		foreach ( $cases as $label => list( $operation, $actor, $status ) ) {
			if ( 404 === $status ) {
				$resources ??= $this->anotherStoresResources();
				$this->assertAnotherStoresResourceIsNotFound( $operation, $resources, $label );
				continue;
			}
			wp_set_current_user( $this->userAs( $actor ) );
			$result = call_user_func( $this->permissionCallback( $operation ), new WP_REST_Request( $operation->method, '/mercatus/v1' . $operation->path ) );

			$this->assertInstanceOf( WP_Error::class, $result, $label );
			$this->assertSame( $status, $result->get_error_data()['status'] ?? null, $label );
		}
		$this->assertNotNull( $resources, 'Tenant routes with a resource in the path exist.' );
	}

	/**
	 * Another store's resources by type, what a request about each needs, and a vendor of a second store.
	 *
	 * @return array{ids: array<string, int>, params: array<string, array<string, mixed>>, etag: string, intruder: int}
	 */
	private function anotherStoresResources(): array {
		$this->vendorWithStore( 'Owner Maps' );
		$product = $this->createProduct();
		$etag    = $this->etag( $this->request( 'GET', '/me/products/' . $product['id'] ) );

		return array(
			'ids'      => array(
				'product' => (int) $product['id'],
				'offer'   => (int) $product['offer']['id'],
			),
			'params'   => array(
				'offer' => array(
					'items' => array(
						array(
							'variation_id' => 0,
							'quantity'     => '99',
						),
					),
				),
			),
			'etag'     => $etag,
			'intruder' => $this->vendorWithStore( 'Intruder Maps' )['user_id'],
		);
	}

	/**
	 * Requests another store's resource as the intruder, with everything the request needs, and expects 404.
	 *
	 * @param RouteOperation                                                                                          $operation Operation.
	 * @param array{ids: array<string, int>, params: array<string, array<string, mixed>>, etag: string, intruder: int} $resources Resources.
	 * @param string                                                                                                  $label     Case.
	 */
	private function assertAnotherStoresResourceIsNotFound( RouteOperation $operation, array $resources, string $label ): void {
		$type = (string) $operation->resourceType;
		$this->assertArrayHasKey( $type, $resources['ids'], $label . ': give this resource type a resource of another store.' );
		wp_set_current_user( $resources['intruder'] );
		$path     = (string) preg_replace( '/\{[a-z_]+\}/', (string) $resources['ids'][ $type ], $operation->path );
		$response = $this->request(
			$operation->method,
			$path,
			$resources['params'][ $type ] ?? array(),
			array(
				'If-Match'        => $resources['etag'],
				'Idempotency-Key' => wp_generate_uuid4(),
			)
		);

		$this->assertSame( 404, $response->get_status(), $label . ' ' . (string) wp_json_encode( $response->get_data() ) );
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
