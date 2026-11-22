<?php
/**
 * Every stock change leaves exactly one movement (CTL-6, CTL-7, DATA-2).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Catalog\Infrastructure\InventoryRepository;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Stock is set or adjusted per variation with the product's ETag; each change writes a movement with the new
 * quantity, is copied to WooCommerce and shows in the projection. A stale ETag changes nothing.
 */
final class InventoryMovementJournalTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function testEveryChangeWritesOneMovement(): void {
		$this->vendorWithStore();
		$product = $this->createProduct(
			array(
				'stock' => array(
					'manage_stock' => true,
					'quantity'     => '5',
				),
			)
		);
		$id      = (int) $product['id'];
		$offer   = (int) $product['offer']['id'];
		$balance = self::kernel()->container()->get( InventoryRepository::class )->forOffer( $offer )[0];

		$this->assertSame( array( array( '5.000000', '5.000000', 'initial' ) ), self::movements( $balance->id ) );
		$set = $this->data( $this->adjust( $id, $offer, array( 'quantity' => '8' ) ), 200 );
		$this->assertSame( '8', $set['stock']['quantity'] );
		$this->data( $this->adjust( $id, $offer, array( 'delta' => '-2' ) ), 200 );

		$this->assertSame(
			array(
				array( '-2.000000', '6.000000', 'adjust' ),
				array( '3.000000', '8.000000', 'set' ),
				array( '5.000000', '5.000000', 'initial' ),
			),
			self::movements( $balance->id )
		);
		$this->assertSame( 6, self::woo( $id )->get_stock_quantity(), 'Copied to WooCommerce.' );
		$this->assertSame( '6.000000', self::productRow( $id )['stock_quantity'] );
	}

	public function testAStaleVersionChangesNothing(): void {
		$this->vendorWithStore();
		$product = $this->createProduct(
			array(
				'stock' => array(
					'manage_stock' => true,
					'quantity'     => '5',
				),
			)
		);
		$id      = (int) $product['id'];
		$offer   = (int) $product['offer']['id'];
		$stale   = $this->etag( $this->request( 'GET', '/me/products/' . $id ) );
		$this->changeProduct( $id, array( 'name' => 'Older map' ) );

		$response = $this->request(
			'PATCH',
			'/me/offers/' . $offer . '/inventory',
			array(
				'items' => array(
					array(
						'variation_id' => 0,
						'quantity'     => '1',
					),
				),
			),
			array( 'If-Match' => $stale )
		);
		$this->assertSame( 'mercatus_version_conflict', $this->data( $response, 409 )['code'] );
		$balance = self::kernel()->container()->get( InventoryRepository::class )->forOffer( $offer )[0];
		$this->assertSame( '5', $balance->quantity->toString() );
		$this->assertCount( 1, self::movements( $balance->id ) );
	}

	public function testStockGoesBelowZeroOnlyWithBackorders(): void {
		$this->vendorWithStore();
		$product = $this->createProduct(
			array(
				'stock' => array(
					'manage_stock' => true,
					'quantity'     => '1',
				),
			)
		);
		$id      = (int) $product['id'];
		$offer   = (int) $product['offer']['id'];

		$this->assertSame( 'mercatus_inventory_invalid', $this->data( $this->adjust( $id, $offer, array( 'delta' => '-2' ) ), 422 )['code'] );
		$stock = $this->data(
			$this->adjust(
				$id,
				$offer,
				array(
					'delta'      => '-2',
					'backorders' => 'yes',
				)
			),
			200
		)['stock'];
		$this->assertSame( array( '-1', 'onbackorder' ), array( $stock['quantity'], $stock['stock_status'] ) );
		$this->assertSame(
			'outofstock',
			$this->data(
				$this->adjust(
					$id,
					$offer,
					array(
						'quantity'   => '0',
						'backorders' => 'no',
					)
				),
				200
			)['stock']['stock_status']
		);
		$this->assertSame( 'mercatus_inventory_invalid', $this->data( $this->adjust( $id, $offer, array( 'quantity' => '1.5' ) ), 422 )['code'], 'Free sells whole pieces.' );
	}

	public function testAVariableProductsStockIsPerVariation(): void {
		$this->vendorWithStore();
		$product = $this->createProduct( self::variable() );
		$small   = (int) $product['variations'][0]['id'];
		$medium  = (int) $product['variations'][1]['id'];
		$etag    = $this->etag( $this->request( 'GET', '/me/products/' . $product['id'] ) );

		$changed = $this->data(
			$this->request(
				'PATCH',
				'/me/offers/' . $product['offer']['id'] . '/inventory',
				array(
					'items' => array(
						array(
							'variation_id' => $medium,
							'manage_stock' => true,
							'quantity'     => '7',
						),
						array(
							'variation_id' => $small,
							'delta'        => '1',
						),
					),
				),
				array( 'If-Match' => $etag )
			),
			200
		);

		$this->assertSame( array( '5', '7' ), array_map( static fn( array $variation ): string => $variation['stock']['quantity'], $changed['variations'] ) );
		$this->assertSame( 7, self::woo( $medium )->get_stock_quantity() );
		$this->assertSame(
			'mercatus_inventory_invalid',
			$this->data(
				$this->request(
					'PATCH',
					'/me/offers/' . $product['offer']['id'] . '/inventory',
					array(
						'items' => array(
							array(
								'variation_id' => 0,
								'quantity'     => '1',
							),
						),
					),
					array( 'If-Match' => $this->etag( $this->request( 'GET', '/me/products/' . $product['id'] ) ) )
				),
				422
			)['code'],
			'A variable product has no stock of its own.'
		);
	}

	/**
	 * Sets or adjusts a simple product's stock at its current version.
	 *
	 * @param int                  $id     Product.
	 * @param int                  $offer  Offer.
	 * @param array<string, mixed> $change The change, for variation 0.
	 */
	private function adjust( int $id, int $offer, array $change ): WP_REST_Response {
		$etag = $this->etag( $this->request( 'GET', '/me/products/' . $id ) );

		return $this->request( 'PATCH', '/me/offers/' . $offer . '/inventory', array( 'items' => array( $change + array( 'variation_id' => 0 ) ) ), array( 'If-Match' => $etag ) );
	}

	/**
	 * A balance's movements, newest first: delta, quantity after and reason.
	 *
	 * @param int $balance_id Balance.
	 * @return list<array{0: string, 1: string, 2: string}>
	 */
	private static function movements( int $balance_id ): array {
		return array_map(
			static fn( array $movement ): array => array( (string) $movement['delta'], (string) $movement['quantity_after'], (string) $movement['reason'] ),
			self::kernel()->container()->get( InventoryRepository::class )->movements( $balance_id )
		);
	}
}
