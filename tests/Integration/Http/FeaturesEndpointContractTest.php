<?php
/**
 * GET /features keeps its contract (TEST-3 row 1, ARCH-6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Http;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Anyone can read it, it matches its own schema, and module versions stay hidden from visitors.
 */
final class FeaturesEndpointContractTest extends WP_UnitTestCase {

	public function testServesTheFeatureManifestToAnyoneWithoutVersions(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/mercatus/v1/features' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertSame( array( array( 'id' => 'core.kernel' ) ), $data['modules'] );
		$this->assertTrue( rest_validate_value_from_schema( $data, $this->schema() ) );
	}

	/**
	 * The route's response schema.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$options = rest_get_server()->get_route_options( '/mercatus/v1/features' );
		$this->assertIsArray( $options );
		$this->assertIsCallable( $options['schema'] ?? null );
		$schema = call_user_func( $options['schema'] );
		$this->assertIsArray( $schema );

		return $schema;
	}
}
