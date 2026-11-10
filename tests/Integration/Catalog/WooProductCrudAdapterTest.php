<?php
/**
 * Content lives in WooCommerce, written through CRUD, with Mercatus's copies of prices and stock (TEST-3 row 6,
 * ARCH-12, G-DB-4, CTL-1, CTL-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Catalog\Infrastructure\WooCatalog;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * The product WooCommerce holds is the vendor's, marked with its seller, with the price active now and the
 * stock copied; variations carry their own; `post_author` is set but decides nothing.
 */
final class WooProductCrudAdapterTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testASimpleProductIsWrittenThroughWooCommerce(): void {
		$vendor  = $this->vendorWithStore();
		$product = $this->createProduct(
			array(
				'sku'         => 'MAP-9',
				'description' => '<p>Hand-coloured</p>',
				'weight'      => '0.4',
				'sale_price'  => '15',
				'stock'       => array(
					'manage_stock' => true,
					'quantity'     => '3',
				),
			)
		);
		$woo     = wc_get_product( (int) $product['id'] );

		$this->assertInstanceOf( WC_Product_Simple::class, $woo );
		$this->assertSame( array( '20', '15', '15', 'MAP-9', '0.4', true, 3, 'draft' ), array( $woo->get_regular_price(), $woo->get_sale_price(), $woo->get_price(), $woo->get_sku(), $woo->get_weight(), $woo->get_manage_stock(), $woo->get_stock_quantity(), $woo->get_status() ) );
		$this->assertSame( '<p>Hand-coloured</p>', $woo->get_description() );
		$this->assertSame( (string) $vendor['vendor_id'], $woo->get_meta( WooCatalog::SELLER_META ) );
		$this->assertSame( (string) $vendor['user_id'], get_post_field( 'post_author', $woo->get_id() ), 'For WooCommerce and other plugins (CTL-1).' );
		$this->assertFalse( user_can( $vendor['user_id'], 'edit_post', $woo->get_id() ), 'Vendors gain no WooCommerce capability (SEC-8).' );
	}

	public function testVariationsCarryTheirOwnContentAndCopies(): void {
		$this->vendorWithStore();
		$product = $this->createProduct( self::variable() );
		$parent  = wc_get_product( (int) $product['id'] );
		$this->assertInstanceOf( WC_Product_Variable::class, $parent );

		$small = wc_get_product( (int) $product['variations'][0]['id'] );
		$this->assertInstanceOf( WC_Product_Variation::class, $small );
		$this->assertSame( array( '10', 'SHIRT-S', true, 4, 'publish' ), array( $small->get_regular_price(), $small->get_sku(), $small->get_manage_stock(), $small->get_stock_quantity(), $small->get_status() ) );
		$this->assertSame( array( 'fit' => 'S' ), $small->get_attributes(), 'The product\'s own attribute, by its value.' );
		$this->assertSame( array( '10', '12' ), array( wc_format_decimal( $parent->get_variation_price( 'min' ), false, true ), wc_format_decimal( $parent->get_variation_price( 'max' ), false, true ) ), 'WooCommerce synced the parent.' );
	}

	public function testReadingGivesTheContentBack(): void {
		$this->vendorWithStore();
		$category = wp_insert_term( 'Maps', 'product_cat' );
		$this->assertIsArray( $category );
		$product = $this->createProduct(
			array(
				'short_description' => 'Old',
				'categories'        => array( (int) $category['term_id'] ),
				'tags'              => array( 'Antique' ),
				'virtual'           => true,
			)
		);

		$read = self::kernel()->container()->get( WooCatalog::class )->read( (int) $product['id'] );
		$this->assertIsArray( $read );
		$this->assertSame( array( 'simple', 'Old map', 'Old', true, array( (int) $category['term_id'] ), array( 'Antique' ) ), array( $read['type'], $read['name'], $read['short_description'], $read['virtual'], $read['categories'], $read['tags'] ) );
		$this->assertSame( (int) $category['term_id'], self::catalogProduct( (int) $product['id'] )->primaryCategoryId );
	}

	public function testDiscardingRemovesAProductAndItsVariations(): void {
		$this->vendorWithStore();
		$product = $this->createProduct( self::variable() );
		$child   = (int) $product['variations'][0]['id'];

		self::kernel()->container()->get( WooCatalog::class )->discard( (int) $product['id'] );

		$this->assertNull( get_post( (int) $product['id'] ) );
		$this->assertNull( get_post( $child ) );
	}
}
