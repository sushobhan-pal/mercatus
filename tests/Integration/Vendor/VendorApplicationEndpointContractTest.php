<?php
/**
 * core.vendor's responses match their schemas (TEST-1 Layer 3).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Vendor;

use WP_UnitTestCase;

/**
 * Registration, an application, the queue and the vendor list, each validated against its route's schema.
 */
final class VendorApplicationEndpointContractTest extends WP_UnitTestCase {

	use VendorTestHelpers;

	public function testResponsesMatchTheirSchemas(): void {
		wp_set_current_user( $this->userAs( 'customer' ) );
		$registration = $this->data( $this->registerVendor(), 201 );
		$this->assertMatches( '/mercatus/v1/vendor-registrations', $registration );

		$id = (int) $registration['application']['id'];
		$this->assertMatches( '/mercatus/v1/vendor-applications/(?P<id>\d+)', $this->data( $this->asAdministrator( 'GET', '/vendor-applications/' . $id ), 200 ) );
		foreach ( $this->data( $this->asAdministrator( 'GET', '/vendor-applications' ), 200 )['items'] as $item ) {
			$this->assertMatches( '/mercatus/v1/vendor-applications', $item );
		}

		$this->data( $this->asAdministrator( 'POST', '/vendor-applications/' . $id . '/approve' ), 200 );
		foreach ( $this->data( $this->asAdministrator( 'GET', '/vendors' ), 200 )['items'] as $item ) {
			$this->assertMatches( '/mercatus/v1/vendors', $item );
		}
	}

	/**
	 * Asserts data matches a route's schema.
	 *
	 * @param string       $route Route regex.
	 * @param array<mixed> $data  Data.
	 */
	private function assertMatches( string $route, array $data ): void {
		$options = rest_get_server()->get_route_options( $route );
		$this->assertIsArray( $options, $route );
		$schema = call_user_func( $options['schema'] );
		$this->assertIsArray( $schema );
		$this->assertTrue( rest_validate_value_from_schema( $data, $schema ), $route );
	}
}
