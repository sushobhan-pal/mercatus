<?php
/**
 * Vendors can't change products through WooCommerce's or WordPress's own APIs (TEST-2, SEC-8, TM-11, G-SEC-7).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Being a vendor grants no WooCommerce capability, so WooCommerce's REST API refuses a vendor's product writes,
 * even on their own product, and WordPress's media endpoint refuses their uploads.
 */
final class WooCommerceProductRestAccessTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testAVendorCannotChangeProductsThroughWooCommercesApi(): void {
		$this->vendorWithStore();
		$id = (int) $this->createProduct()['id'];

		$attempts = array(
			array( 'POST', '/wc/v3/products', array( 'name' => 'Sneaky' ) ),
			array( 'PUT', '/wc/v3/products/' . $id, array( 'regular_price' => '1' ) ),
			array( 'DELETE', '/wc/v3/products/' . $id, array( 'force' => true ) ),
		);
		foreach ( $attempts as list( $method, $route, $params ) ) {
			$request = new WP_REST_Request( $method, $route );
			$request->set_body_params( $params );
			$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ), $method . ' ' . $route );
		}
		$this->assertSame( '20', self::woo( $id )->get_regular_price() );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function testAVendorCannotUploadThroughWordPress(): void {
		$this->vendorWithStore();
		$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=map.jpg' );
		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_body( 'not really an image' );

		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
	}
}
