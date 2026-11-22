<?php
/**
 * A vendor's images are theirs alone (SEC-4, CTL-14).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use WP_Post;
use WP_UnitTestCase;

/**
 * Another vendor's image, or an attachment no vendor uploaded, is not found: it can't be seen or deleted, and
 * it isn't in the list.
 */
final class MediaCrossVendorDeleteTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	public function testAnotherVendorCanNeitherSeeNorDeleteAnImage(): void {
		$owner = $this->vendorWithStore( 'Owner Maps' );
		$image = $this->uploadImage();
		$file  = (string) get_attached_file( (int) $image['id'] );
		$this->vendorWithStore( 'Intruder Maps' );

		$this->assertSame( 'mercatus_media_unknown', $this->data( $this->request( 'GET', '/me/media/' . $image['id'] ), 404 )['code'] );
		$this->assertSame( 'mercatus_media_unknown', $this->data( $this->request( 'DELETE', '/me/media/' . $image['id'] ), 404 )['code'] );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/me/media' ), 200 )['items'], 'Their list has only their own.' );
		$this->assertInstanceOf( WP_Post::class, get_post( (int) $image['id'] ) );
		$this->assertFileExists( $file );

		wp_set_current_user( $owner['user_id'] );
		$this->assertSame( array( $image['id'] ), array_column( $this->data( $this->request( 'GET', '/me/media' ), 200 )['items'], 'id' ) );
	}

	public function testAnAttachmentNoVendorUploadedIsNotFound(): void {
		$this->vendorWithStore();
		$attachment = self::siteAttachment();

		$this->assertSame( 404, $this->request( 'GET', '/me/media/' . $attachment )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', '/me/media/' . $attachment )->get_status() );
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment ) );
	}
}
