<?php
/**
 * A vendor's own change updates the projection at once (TEST-3 row 6, CAT-5, ARCH-12, CTL-9, CTL-13).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use WP_UnitTestCase;

/**
 * Every product has a row for itself, a variable one a row per variation too. A row is purchasable only while
 * buyers may see the product, it has a price and stock allows it; its card holds what a list renders.
 */
final class OfferAvailabilityProjectionSyncTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testAVendorsOwnChangeUpdatesTheRowAtOnce(): void {
		$vendor  = $this->vendorWithStore( 'Rare Maps' );
		$product = $this->createProduct( array( 'sku' => 'MAP-1' ) );
		$id      = (int) $product['id'];

		$row  = self::productRow( $id );
		$card = json_decode( (string) $row['card'], true );
		$this->assertSame( array( 'draft', '0', '20.000000', 'Old map', 'MAP-1', (string) $vendor['vendor_id'] ), array( $row['status'], $row['is_purchasable'], $row['effective_price'], $row['name'], $row['sku'], $row['vendor_id'] ) );
		$this->assertIsArray( $card );
		$this->assertSame( array( $id, 'Rare Maps' ), array( $card['id'], $card['store']['name'] ) );

		$this->openMyStore();
		$this->act( $id, 'publish' );
		$this->assertSame( array( 'published', '1' ), array( self::productRow( $id )['status'], self::productRow( $id )['is_purchasable'] ) );

		$this->changeProduct(
			$id,
			array(
				'regular_price' => '25',
				'sale_price'    => '18',
			)
		);
		$this->assertSame( '18.000000', self::productRow( $id )['effective_price'] );

		$etag = $this->etag( $this->request( 'GET', '/me/products/' . $id ) );
		$this->data(
			$this->request(
				'PATCH',
				'/me/offers/' . $product['offer']['id'] . '/inventory',
				array(
					'items' => array(
						array(
							'variation_id' => 0,
							'manage_stock' => true,
							'quantity'     => '0',
						),
					),
				),
				array( 'If-Match' => $etag )
			),
			200
		);
		$this->assertSame( array( '0', 'outofstock' ), array( self::productRow( $id )['is_purchasable'], self::productRow( $id )['stock_status'] ), 'Out of stock.' );
	}

	public function testAHiddenStoresProductIsNotPurchasable(): void {
		$this->vendorWithStore();
		$id = (int) $this->createProduct()['id'];
		$this->act( $id, 'publish' );

		$this->assertSame( array( 'published', '0' ), array( self::productRow( $id )['status'], self::productRow( $id )['is_purchasable'] ) );
	}

	public function testAVariableProductHasARowPerVariation(): void {
		$this->vendorWithStore();
		$this->openMyStore();
		$product = $this->createProduct( self::variable() );
		$id      = (int) $product['id'];
		$small   = (int) $product['variations'][0]['id'];
		$medium  = (int) $product['variations'][1]['id'];
		$this->act( $id, 'publish' );

		$this->assertCount( 3, self::rows( $id ) );
		$row = self::productRow( $id );
		$this->assertSame( array( '1', '10.000000', '10.000000', '12.000000' ), array( $row['is_purchasable'], $row['effective_price'], $row['min_price'], $row['max_price'] ) );

		$this->changeProduct(
			$id,
			array(
				'variations' => array(
					array(
						'id'      => $small,
						'enabled' => false,
					),
					array( 'id' => $medium ),
				),
			)
		);
		$rows = array();
		foreach ( self::rows( $id ) as $variation ) {
			$rows[ (int) $variation['variation_id'] ] = array( $variation['status'], $variation['is_purchasable'] );
		}
		$this->assertSame( array( 'disabled', '0' ), $rows[ $small ] );
		$this->assertSame( array( 'published', '1' ), $rows[ $medium ] );
		$this->assertSame( array( '12.000000', '12.000000' ), array( self::productRow( $id )['min_price'], self::productRow( $id )['max_price'] ) );

		$this->changeProduct( $id, array( 'variations' => array( array( 'id' => $medium ) ) ) );
		$this->assertCount( 2, self::rows( $id ), 'A deleted variation\'s row goes.' );
	}

	public function testATrashedProductKeepsItsRowButIsNotPurchasable(): void {
		$this->vendorWithStore();
		$this->openMyStore();
		$id = (int) $this->createProduct()['id'];
		$this->act( $id, 'publish' );
		$this->data( $this->request( 'DELETE', '/me/products/' . $id ), 200 );

		$this->assertSame( array( 'trashed', '0' ), array( self::productRow( $id )['status'], self::productRow( $id )['is_purchasable'] ) );
	}
}
