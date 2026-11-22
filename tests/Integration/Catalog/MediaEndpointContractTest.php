<?php
/**
 * The vendor's media endpoints (API-2, CTL-14, CTL-24).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Shared\Events\DomainEvent;
use WP_UnitTestCase;

/**
 * An upload answers with the image; the list is newest first, with cursors. Deleting an image, from the
 * dashboard or from WordPress's media library, takes it off the vendor's products, their variations and their
 * store, and frees its space.
 */
final class MediaEndpointContractTest extends WP_UnitTestCase {

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
		$this->remove_added_uploads();
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

	public function testAnUploadAnswersWithTheImage(): void {
		$vendor = $this->vendorWithStore();
		$image  = $this->uploadImage( self::jpeg( 400, 300 ), 'Old map.jpg' );

		$this->assertSame( array( 'id', 'url', 'thumbnail_url', 'mime', 'bytes', 'width', 'height', 'created_at' ), array_keys( $image ) );
		$this->assertSame( array( 'image/jpeg', 400, 300 ), array( $image['mime'], $image['width'], $image['height'] ) );
		$this->assertSame( wp_get_attachment_url( (int) $image['id'] ), $image['url'] );
		$this->assertStringEndsWith( '-150x150.jpg', (string) $image['thumbnail_url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $image['created_at'] );
		$this->assertSame( 'attachment', get_post_type( (int) $image['id'] ) );
		$this->assertSame( $vendor['user_id'], (int) get_post_field( 'post_author', (int) $image['id'] ), 'The vendor\'s own attachment.' );
		$this->assertContains( 'catalog.media.uploaded', $this->events );
		$this->assertSame( $image, $this->data( $this->request( 'GET', '/me/media/' . $image['id'] ), 200 ) );
	}

	public function testAnUploadWithoutAFileIsRefused(): void {
		$this->vendorWithStore();

		$this->assertSame( 'mercatus_media_upload_failed', $this->data( $this->request( 'POST', '/me/media' ), 400 )['code'] );
	}

	public function testAnUploadRetriedWithItsKeyRunsOnce(): void {
		$this->vendorWithStore();
		$key   = array( 'Idempotency-Key' => 'upload-map-1' );
		$image = self::jpeg();

		$first = $this->data( $this->upload( $image, 'map.jpg', $key ), 201 );
		$again = $this->upload( $image, 'map.jpg', $key );

		$this->assertSame( $first['id'], $again->get_data()['id'] ?? null, 'The stored response, not a second image.' );
		$this->assertCount( 1, $this->data( $this->request( 'GET', '/me/media' ), 200 )['items'] );
		$this->assertSame( 'mercatus_idempotency_mismatch', $this->data( $this->upload( self::png(), 'map.jpg', $key ), 422 )['code'], 'Another file under the same key.' );
	}

	public function testTheListIsNewestFirstWithCursors(): void {
		$this->vendorWithStore();
		$ids = array();
		foreach ( array( 'a', 'b', 'c' ) as $name ) {
			$ids[] = $this->uploadImage( null, $name . '.jpg' )['id'];
		}

		$first = $this->data( $this->request( 'GET', '/me/media', array( 'per_page' => 2 ) ), 200 );
		$this->assertSame( array( $ids[2], $ids[1] ), array_column( $first['items'], 'id' ) );
		$this->assertTrue( $first['has_more'] );
		$next = $this->data(
			$this->request(
				'GET',
				'/me/media',
				array(
					'per_page' => 2,
					'cursor'   => $first['next_cursor'],
				)
			),
			200
		);
		$this->assertSame( array( $ids[0] ), array_column( $next['items'], 'id' ) );
		$this->assertFalse( $next['has_more'] );
		$this->assertSame( $ids, array_column( $this->data( $this->request( 'GET', '/me/media', array( 'order' => 'asc' ) ), 200 )['items'], 'id' ), 'Oldest first when asked.' );
	}

	public function testDeletingTakesTheImageOffProductsAndTheStore(): void {
		$this->vendorWithStore();
		$a                                   = (int) $this->uploadImage( null, 'a.jpg' )['id'];
		$b                                   = (int) $this->uploadImage( null, 'b.jpg' )['id'];
		$c                                   = (int) $this->uploadImage( null, 'c.jpg' )['id'];
		$featured                            = $this->createProduct( array( 'images' => array( $a, $b ) ) );
		$gallery                             = $this->createProduct(
			array(
				'name'   => 'Atlas',
				'images' => array( $b, $a ),
			)
		);
		$fields                              = self::variable();
		$fields['variations'][1]['image_id'] = $a;
		$shirt                               = $this->createProduct( $fields );
		$this->data(
			$this->call(
				'PATCH',
				'/me/store',
				array(
					'logo_id'   => $a,
					'banner_id' => $c,
				),
				$this->etag( $this->call( 'GET', '/me/store' ) )
			),
			200
		);
		$this->assertSame( $a, json_decode( (string) self::productRow( (int) $featured['id'] )['card'], true )['image']['id'] ?? null );
		$file         = (string) get_attached_file( $a );
		$before       = $this->etag( $this->request( 'GET', '/me/products/' . $featured['id'] ) );
		$this->events = array();

		$deleted = $this->data( $this->request( 'DELETE', '/me/media/' . $a ), 200 );

		$this->assertSame( $a, $deleted['id'], 'It answers with what was deleted.' );
		$this->assertNull( get_post( $a ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertSame( 404, $this->request( 'GET', '/me/media/' . $a )->get_status() );
		$this->assertSame( array( $b ), array_column( $this->product( (int) $featured['id'] )['images'], 'id' ), 'Its image is gone; its gallery stays.' );
		$this->assertSame( array( $b ), array_column( $this->product( (int) $gallery['id'] )['images'], 'id' ), 'Gone from a gallery too.' );
		$this->assertNull( $this->product( (int) $shirt['id'] )['variations'][1]['image'], 'And from a variation.' );
		$this->assertNotSame( $before, $this->etag( $this->request( 'GET', '/me/products/' . $featured['id'] ) ), 'A product changed, so its version did.' );
		$this->assertSame( $b, json_decode( (string) self::productRow( (int) $featured['id'] )['card'], true )['image']['id'] ?? null, 'Its card shows the next image.' );
		$store = $this->data( $this->call( 'GET', '/me/store' ), 200 );
		$this->assertSame( array( null, $c ), array( $store['logo_id'], $store['banner_id'] ) );
		foreach ( array( 'catalog.media.deleted', 'catalog.product.updated', 'store.store.updated' ) as $event ) {
			$this->assertContains( $event, $this->events );
		}
	}

	public function testAnImageDeletedFromWordPressIsForgottenToo(): void {
		$vendor  = $this->vendorWithStore();
		$image   = (int) $this->uploadImage()['id'];
		$product = $this->createProduct( array( 'images' => array( $image ) ) );
		$this->data( $this->call( 'PATCH', '/me/store', array( 'logo_id' => $image ), $this->etag( $this->call( 'GET', '/me/store' ) ) ), 200 );

		wp_set_current_user( $this->userAs( 'administrator' ) );
		wp_delete_attachment( $image, true );
		wp_set_current_user( $vendor['user_id'] );

		$this->assertSame( array(), $this->product( (int) $product['id'] )['images'] );
		$this->assertNull( $this->data( $this->call( 'GET', '/me/store' ), 200 )['logo_id'] );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/me/media' ), 200 )['items'] );
		$this->assertSame( 0, ( (array) $this->data( $this->request( 'GET', '/me' ), 200 )['sections'] )['media']['used_bytes'], 'Its space is free again.' );
	}

	/**
	 * One of the current vendor's products.
	 *
	 * @param int $id Product ID.
	 * @return array<string, mixed>
	 */
	private function product( int $id ): array {
		return $this->data( $this->request( 'GET', '/me/products/' . $id ), 200 );
	}
}
