<?php
/**
 * The vendor's product endpoints (API-2, API-1, PLAT-7, PLAT-9, CTL-3, CTL-5, CTL-17).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Shared\Events\DomainEvent;
use WC_Product_Variable;
use WP_UnitTestCase;

/**
 * Creating sets stock, so it needs an Idempotency-Key; every change needs the product's ETag. A product's ID
 * is its WooCommerce product ID, its state changes are actions, and the list reads the projection, newest
 * first, with cursors.
 */
final class ProductEndpointContractTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	/**
	 * Events published, by name.
	 *
	 * @var list<string>
	 */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		add_action( 'mercatus_event', array( $this, 'onEvent' ) );
	}

	public function tear_down(): void {
		remove_action( 'mercatus_event', array( $this, 'onEvent' ) );
		parent::tear_down();
	}

	/**
	 * Records an event.
	 *
	 * @param DomainEvent $event Event.
	 */
	public function onEvent( DomainEvent $event ): void {
		$this->events[] = $event->name;
	}

	public function testCreatingNeedsAnIdempotencyKeyAndRunsOnce(): void {
		$this->vendorWithStore();
		$this->assertSame( 'mercatus_idempotency_key_required', $this->data( $this->request( 'POST', '/me/products', self::simple() ), 400 )['code'] );

		$headers = array( 'Idempotency-Key' => 'create-map-1' );
		$first   = $this->data( $this->request( 'POST', '/me/products', self::simple(), $headers ), 201 );
		$again   = $this->request( 'POST', '/me/products', self::simple(), $headers );

		$this->assertSame( $first['id'], $again->get_data()['id'] ?? null, 'The stored response, not a second product.' );
		$this->assertCount( 1, $this->data( $this->request( 'GET', '/me/products' ), 200 )['items'] );
	}

	public function testAProductIsItsWooCommerceProduct(): void {
		$this->vendorWithStore();
		$product = $this->createProduct( array( 'sku' => 'MAP-1' ) );

		$this->assertSame( 'product', get_post_type( $product['id'] ), 'Its ID is the WooCommerce product ID (CTL-3).' );
		$this->assertSame( array( 'simple', 'draft', 'draft', '20', '20', 'MAP-1' ), array( $product['type'], $product['status'], $product['product_status'], $product['regular_price'], $product['effective_price'], $product['sku'] ) );
		$this->assertSame( 'paused', $product['offer']['status'] );
		$this->assertSame(
			array(
				'manage_stock' => false,
				'quantity'     => '0',
				'stock_status' => 'instock',
				'backorders'   => 'no',
			),
			$product['stock']
		);
		$this->assertContains( 'catalog.product.created', $this->events );
	}

	public function testAChangeNeedsTheVersionItWasBasedOn(): void {
		$this->vendorWithStore();
		$id   = (int) $this->createProduct()['id'];
		$etag = $this->etag( $this->request( 'GET', '/me/products/' . $id ) );

		$this->data( $this->request( 'PATCH', '/me/products/' . $id, array( 'name' => 'Older map' ) ), 428 );
		$changed = $this->request( 'PATCH', '/me/products/' . $id, array( 'name' => 'Older map' ), array( 'If-Match' => $etag ) );
		$this->assertSame( 'Older map', $this->data( $changed, 200 )['name'] );
		$this->assertNotSame( $etag, $this->etag( $changed ) );
		$this->assertSame( 'mercatus_version_conflict', $this->data( $this->request( 'PATCH', '/me/products/' . $id, array( 'name' => 'Oldest map' ), array( 'If-Match' => $etag ) ), 409 )['code'] );
		$this->assertSame( 'Older map', get_the_title( $id ) );
	}

	public function testTheListIsNewestFirstWithCursorsAStateFilterAndASearch(): void {
		$this->vendorWithStore();
		$first  = (int) $this->createProduct( array( 'name' => 'Atlas' ) )['id'];
		$second = (int) $this->createProduct(
			array(
				'name' => 'Globe',
				'sku'  => 'G-1',
			)
		)['id'];
		$third  = (int) $this->createProduct( array( 'name' => 'Chart' ) )['id'];
		$this->act( $third, 'publish' );

		$page = $this->data( $this->request( 'GET', '/me/products', array( 'per_page' => 2 ) ), 200 );
		$this->assertSame( array( $third, $second ), array_column( $page['items'], 'id' ) );
		$this->assertTrue( $page['has_more'] );
		$next = $this->data(
			$this->request(
				'GET',
				'/me/products',
				array(
					'per_page' => 2,
					'cursor'   => $page['next_cursor'],
				)
			),
			200
		);
		$this->assertSame( array( $first ), array_column( $next['items'], 'id' ) );

		$this->assertSame( array( $second, $first ), array_column( $this->data( $this->request( 'GET', '/me/products', array( 'status' => 'draft' ) ), 200 )['items'], 'id' ) );
		$this->assertSame( array( $third ), array_column( $this->data( $this->request( 'GET', '/me/products', array( 'status' => 'published' ) ), 200 )['items'], 'id' ) );
		$this->assertSame( array( $first ), array_column( $this->data( $this->request( 'GET', '/me/products', array( 'search' => 'At' ) ), 200 )['items'], 'id' ) );
		$this->assertSame( array( $second ), array_column( $this->data( $this->request( 'GET', '/me/products', array( 'search' => 'G-1' ) ), 200 )['items'], 'id' ), 'Or by SKU.' );
	}

	public function testPublishingNeedsAPriceAndFollowsTheStore(): void {
		$this->vendorWithStore();
		$id = (int) $this->createProduct( array( 'regular_price' => '' ) )['id'];

		$this->assertSame( array( 'mercatus_product_incomplete', 'regular_price' ), $this->refusal( $this->request( 'POST', '/me/products/' . $id . '/publish' ), 422 ) );
		$this->changeProduct( $id, array( 'regular_price' => '0' ) );
		$this->assertSame( 'published', $this->act( $id, 'publish' )['status'], 'Free products are allowed.' );
		$this->assertSame( 'private', get_post_status( $id ), 'The store is still hidden (CTL-13).' );

		$this->openMyStore();
		$other = (int) $this->createProduct()['id'];
		$this->act( $other, 'publish' );
		$this->assertSame( 'publish', get_post_status( $other ) );
		$this->assertSame( 'unpublished', $this->act( $other, 'unpublish' )['status'] );
		$this->assertSame( 'private', get_post_status( $other ) );
		$this->assertContains( 'catalog.product.published', $this->events );
		$this->assertContains( 'catalog.product.unpublished', $this->events );
	}

	public function testDeletingMovesTheProductToTheTrash(): void {
		$this->vendorWithStore();
		$id = (int) $this->createProduct()['id'];

		$this->assertSame( 'trashed', $this->data( $this->request( 'DELETE', '/me/products/' . $id ), 200 )['status'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertNotEmpty( get_post_meta( $id, '_wp_trash_meta_status', true ), 'Restorable from WooCommerce\'s trash.' );
		$this->assertSame( 'mercatus_product_not_editable', $this->changeProduct( $id, array( 'name' => 'Back' ), 409 )['code'] );
	}

	public function testAVariableProductKeepsTheVariationsTheListNames(): void {
		$this->vendorWithStore();
		$product = $this->createProduct( self::variable() );
		$this->assertSame( 'variable', $product['type'] );
		$this->assertSame( array( '10', '12' ), array_column( $product['variations'], 'regular_price' ) );
		$this->assertSame( array( 'Fit' => 'S' ), $product['variations'][0]['attributes'] );
		$this->assertSame( '4', $product['variations'][0]['stock']['quantity'] );

		$small   = (int) $product['variations'][0]['id'];
		$medium  = (int) $product['variations'][1]['id'];
		$changed = $this->changeProduct(
			(int) $product['id'],
			array(
				'variations' => array(
					array(
						'id'            => $small,
						'regular_price' => '11',
						'enabled'       => false,
					),
				),
			)
		);

		$this->assertSame( array( $small ), array_column( $changed['variations'], 'id' ), 'A variation the list leaves out is deleted.' );
		$this->assertSame( array( '11', false ), array( $changed['variations'][0]['regular_price'], $changed['variations'][0]['enabled'] ) );
		$this->assertNull( get_post( $medium ) );
		$parent = wc_get_product( (int) $product['id'] );
		$this->assertInstanceOf( WC_Product_Variable::class, $parent );
	}

	public function testCategoriesComeOneLevelAtATimeByName(): void {
		$maps   = wp_insert_term( 'Maps', 'product_cat' );
		$atlas  = wp_insert_term( 'Atlases', 'product_cat' );
		$prints = wp_insert_term( 'Prints', 'product_cat' );
		$this->assertIsArray( $maps );
		$this->assertIsArray( $atlas );
		$this->assertIsArray( $prints );
		wp_insert_term( 'Sea charts', 'product_cat', array( 'parent' => absint( $maps['term_id'] ) ) );
		wp_set_current_user( 0 );

		$top = $this->data( $this->request( 'GET', '/catalog/categories', array( 'per_page' => 2 ) ), 200 );
		$this->assertSame( array( 'Atlases', 'Maps' ), array_column( $top['items'], 'name' ) );
		$this->assertSame( array( false, true ), array_column( $top['items'], 'has_children' ) );
		$rest = $this->data(
			$this->request(
				'GET',
				'/catalog/categories',
				array(
					'per_page' => 2,
					'cursor'   => $top['next_cursor'],
				)
			),
			200
		);
		$this->assertContains( 'Prints', array_column( $rest['items'], 'name' ) );
		$this->assertSame( array( 'Sea charts' ), array_column( $this->data( $this->request( 'GET', '/catalog/categories', array( 'parent_id' => (int) $maps['term_id'] ) ), 200 )['items'], 'name' ) );
		$this->assertSame( array( 'Prints' ), array_column( $this->data( $this->request( 'GET', '/catalog/categories', array( 'search' => 'rin' ) ), 200 )['items'], 'name' ) );
	}

	public function testAProductMayOnlyUseCategoriesThatExist(): void {
		$this->vendorWithStore();

		$this->assertSame( array( 'mercatus_product_invalid', 'categories' ), $this->refusal( $this->request( 'POST', '/me/products', self::simple() + array( 'categories' => array( 999999 ) ), array( 'Idempotency-Key' => 'cat-1' ) ), 422 ) );
	}
}
