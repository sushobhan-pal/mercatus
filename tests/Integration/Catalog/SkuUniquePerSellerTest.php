<?php
/**
 * SKUs are unique per seller, the marketplace counting as one (CTL-26).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use WC_Data_Exception;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Vendors may share manufacturers' SKUs; one seller can't use a SKU twice. Where WooCommerce looks a product up
 * by SKU alone, it gets the marketplace's own product, else the only seller's, and never a guess between
 * vendors.
 */
final class SkuUniquePerSellerTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testVendorsMayShareASkuButNotReuseTheirOwn(): void {
		$first = $this->vendorWithStore( 'First Maps' );
		$a     = (int) $this->createProduct( array( 'sku' => 'ATLAS-1' ) )['id'];
		$this->vendorWithStore( 'Second Maps' );
		$b = (int) $this->createProduct( array( 'sku' => 'ATLAS-1' ) )['id'];

		$this->assertSame( array( 'ATLAS-1', 'ATLAS-1' ), array( self::woo( $a )->get_sku(), self::woo( $b )->get_sku() ) );
		$this->assertSame( 0, wc_get_product_id_by_sku( 'ATLAS-1' ), 'Several vendors: no guess.' );

		wp_set_current_user( $first['user_id'] );
		$this->assertSame( array( 'mercatus_product_sku_taken', 'sku' ), $this->refusal( $this->request( 'POST', '/me/products', self::simple() + array( 'sku' => 'ATLAS-1' ), array( 'Idempotency-Key' => 'sku-again' ) ), 409 ) );
		$globe = (int) $this->createProduct( array( 'sku' => 'GLOBE-1' ) )['id'];
		$this->assertSame( $globe, wc_get_product_id_by_sku( 'GLOBE-1' ), 'One seller: theirs.' );
		$this->assertSame( array( 'mercatus_product_sku_taken', 'sku' ), $this->refusal( $this->request( 'PATCH', '/me/products/' . $globe, array( 'sku' => 'ATLAS-1' ), array( 'If-Match' => $this->etag( $this->request( 'GET', '/me/products/' . $globe ) ) ) ), 409 ) );
		$this->assertSame( 'GLOBE-1', self::woo( $globe )->get_sku() );
	}

	public function testAChangeCannotNameOneSkuTwice(): void {
		$this->vendorWithStore();
		$fields                         = self::variable();
		$fields['variations'][1]['sku'] = 'SHIRT-S';

		$this->assertSame( array( 'mercatus_product_sku_taken', 'sku' ), $this->refusal( $this->request( 'POST', '/me/products', $fields, array( 'Idempotency-Key' => 'twice' ) ), 409 ) );
	}

	public function testTheMarketplaceIsOneSellerToo(): void {
		wp_set_current_user( $this->userAs( 'administrator' ) );
		$house = new WC_Product_Simple();
		$house->set_name( 'House map' );
		$house->set_sku( 'HOUSE-1' );
		$house->save();
		$this->vendorWithStore();
		$this->createProduct( array( 'sku' => 'HOUSE-1' ) );

		$this->assertSame( $house->get_id(), wc_get_product_id_by_sku( 'HOUSE-1' ), 'The marketplace\'s own, so an import never lands on a vendor\'s product.' );
		$again = new WC_Product_Simple();
		$again->set_name( 'Second house map' );
		$this->expectException( WC_Data_Exception::class );
		$again->set_sku( 'HOUSE-1' );
	}
}
