<?php
/**
 * Requests never set ownership or state, and another vendor's product doesn't exist for you (TEST-3 row 6,
 * TM-3, TM-4, TM-9, SEC-3, SEC-4).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use WP_UnitTestCase;

/**
 * Fields such as `vendor_id`, `status` or `scope` in a body are dropped before the command sees them; a vendor
 * asking for another vendor's product or offer, in any way, is told it doesn't exist.
 */
final class ProductMassAssignmentOwnershipTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testABodyNeverSetsOwnershipOrState(): void {
		$other = $this->vendorWithStore( 'Other Maps' );
		$mine  = $this->vendorWithStore( 'My Maps' );

		$product = $this->createProduct(
			array(
				'vendor_id'       => $other['vendor_id'],
				'owner_vendor_id' => $other['vendor_id'],
				'status'          => 'approved',
				'product_status'  => 'approved',
				'scope'           => 'marketplace',
				'woo_product_id'  => 1,
				'offer'           => array( 'status' => 'active' ),
			)
		);
		$this->assertSame( array( $mine['vendor_id'], 'draft', 'paused' ), array( $product['vendor_id'], $product['status'], $product['offer']['status'] ) );
		$catalog = self::catalogProduct( (int) $product['id'] );
		$this->assertSame( array( 'vendor', $mine['vendor_id'], (int) $product['id'] ), array( $catalog->scope, $catalog->ownerVendorId, $catalog->wooProductId ) );

		$changed = $this->changeProduct(
			(int) $product['id'],
			array(
				'name'      => 'Still mine',
				'status'    => 'approved',
				'vendor_id' => $other['vendor_id'],
				'type'      => 'variable',
			)
		);
		$this->assertSame( array( 'Still mine', 'draft', $mine['vendor_id'], 'simple' ), array( $changed['name'], $changed['status'], $changed['vendor_id'], $changed['type'] ) );
	}

	public function testAnotherVendorsProductDoesNotExistForYou(): void {
		$this->vendorWithStore( 'Owner Maps' );
		$product = $this->createProduct();
		$id      = (int) $product['id'];
		$offer   = (int) $product['offer']['id'];
		$etag    = $this->etag( $this->request( 'GET', '/me/products/' . $id ) );
		$this->vendorWithStore( 'Intruder Maps' );

		$attempts = array(
			array( 'GET', '/me/products/' . $id, array() ),
			array( 'PATCH', '/me/products/' . $id, array( 'name' => 'Mine now' ) ),
			array( 'DELETE', '/me/products/' . $id, array() ),
			array( 'POST', '/me/products/' . $id . '/publish', array() ),
			array( 'POST', '/me/products/' . $id . '/unpublish', array() ),
			array(
				'PATCH',
				'/me/offers/' . $offer . '/inventory',
				array(
					'items' => array(
						array(
							'variation_id' => 0,
							'quantity'     => '99',
						),
					),
				),
			),
		);
		foreach ( $attempts as list( $method, $route, $params ) ) {
			$this->assertSame( 'mercatus_product_unknown', $this->data( $this->request( $method, $route, $params, array( 'If-Match' => $etag ) ), 404 )['code'], $method . ' ' . $route );
		}
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/me/products' ), 200 )['items'] );
		$this->assertSame( array( 'Old map', 'draft' ), array( get_the_title( $id ), get_post_status( $id ) ) );
	}

	public function testAnUnknownProductIsNotFound(): void {
		$this->vendorWithStore();

		$this->assertSame( 'mercatus_product_unknown', $this->data( $this->request( 'GET', '/me/products/999999' ), 404 )['code'] );
		$page = self::factory()->post->create();
		$this->assertIsInt( $page );
		$this->assertSame( 'mercatus_product_unknown', $this->data( $this->request( 'GET', '/me/products/' . $page ), 404 )['code'], 'A post that isn\'t a catalog product.' );
	}
}
