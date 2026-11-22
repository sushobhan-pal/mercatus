<?php
/**
 * Whose images are whose, and how much room each vendor has (SEC-7, CTL-14, CTL-24).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use DateTimeImmutable;
use DateTimeZone;
use Mercatus\Core\Catalog\Application\CatalogSettings;
use Mercatus\Core\Catalog\Domain\Media;
use Mercatus\Core\Catalog\Infrastructure\MediaRepository;
use WP_UnitTestCase;

/**
 * An upload is refused over the size limit, or when it would overflow its vendor's space, counting every size
 * WordPress makes of it; then nothing is kept. Products and stores take only their own vendor's uploads.
 */
final class MediaOwnershipQuotaTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	private const MB = 1048576;

	/**
	 * Settings to restore.
	 *
	 * @var array<string, int>
	 */
	private array $settings = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( array( CatalogSettings::UPLOAD_MAX_MB, CatalogSettings::VENDOR_MEDIA_QUOTA_MB ) as $key ) {
			$this->settings[ $key ] = self::kernel()->settings()->int( $key );
		}
	}

	public function tear_down(): void {
		foreach ( $this->settings as $key => $value ) {
			self::kernel()->settings()->set( $key, $value );
		}
		$this->remove_added_uploads();
		parent::tear_down();
	}

	public function testAnImageOverTheSizeLimitIsRefused(): void {
		$this->vendorWithStore();
		self::kernel()->settings()->set( CatalogSettings::UPLOAD_MAX_MB, 1 );

		$refusal = $this->data( $this->upload( self::jpeg() . str_repeat( "\0", self::MB ), 'big.jpg' ), 413 );

		$this->assertSame( array( 'mercatus_media_too_large', self::MB ), array( $refusal['code'], ( (array) $refusal['details'] )['max_bytes'] ?? null ) );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/me/media' ), 200 )['items'] );
	}

	public function testAVendorsSpaceCountsEverySizeAndShowsInMe(): void {
		$this->vendorWithStore();
		$image = $this->uploadImage( self::jpeg( 400, 300 ) );

		$meta  = wp_get_attachment_metadata( (int) $image['id'] );
		$sizes = is_array( $meta ) ? $meta['sizes'] ?? array() : array();
		$main  = (string) get_attached_file( (int) $image['id'] );
		$bytes = (int) filesize( $main );
		foreach ( $sizes as $size ) {
			$bytes += (int) filesize( dirname( $main ) . '/' . $size['file'] );
		}
		$this->assertNotEmpty( $sizes, 'WordPress made smaller sizes.' );
		$this->assertSame( $bytes, $image['bytes'], 'The file and every size.' );

		$section = (array) ( (array) $this->data( $this->request( 'GET', '/me' ), 200 )['sections'] )['media'];
		$this->assertSame( $bytes, $section['used_bytes'] );
		$this->assertSame( $this->settings[ CatalogSettings::VENDOR_MEDIA_QUOTA_MB ] * self::MB, $section['quota_bytes'] );
		$this->assertSame( $this->settings[ CatalogSettings::UPLOAD_MAX_MB ] * self::MB, $section['upload_max_bytes'] );
		$this->assertContains( 'image/jpeg', $section['types'] );
		$this->assertNotContains( 'image/svg+xml', $section['types'] );
	}

	public function testAnUploadThatWouldOverflowTheSpaceIsRefused(): void {
		$vendor = $this->vendorWithStore();
		self::kernel()->settings()->set( CatalogSettings::VENDOR_MEDIA_QUOTA_MB, 1 );
		$this->fill( $vendor['vendor_id'], self::MB - 100 );

		$refusal = $this->data( $this->upload( self::jpeg(), 'one-more.jpg' ), 422 );

		$this->assertSame( 'mercatus_media_quota_exceeded', $refusal['code'] );
		$details = (array) $refusal['details'];
		$this->assertSame( array( self::MB, self::MB - 100 ), array( $details['quota_bytes'] ?? null, $details['used_bytes'] ?? null ) );
	}

	public function testWhenTheSizesWordPressMakesWouldOverflowTheSpaceNothingIsKept(): void {
		$vendor = $this->vendorWithStore();
		self::kernel()->settings()->set( CatalogSettings::VENDOR_MEDIA_QUOTA_MB, 1 );
		$upload = self::jpeg( 400, 300 );
		// Room for the upload as sent, not for it with its sizes.
		$this->fill( $vendor['vendor_id'], self::MB - strlen( $upload ) - 10 );
		$before = self::attachmentCount();

		$this->assertSame( 'mercatus_media_quota_exceeded', $this->data( $this->upload( $upload, 'overflow.jpg' ), 422 )['code'] );
		$this->assertSame( $before, self::attachmentCount(), 'The attachment was deleted.' );
		$this->assertSame( array(), glob( wp_get_upload_dir()['path'] . '/overflow*' ), 'So were its files.' );
	}

	public function testAProductTakesOnlyItsVendorsImages(): void {
		$this->vendorWithStore( 'Owner Maps' );
		$theirs = (int) $this->uploadImage()['id'];
		$this->vendorWithStore( 'Other Maps' );
		$mine     = (int) $this->uploadImage()['id'];
		$site     = self::siteAttachment();
		$variable = self::variable();

		$variable['variations'][0]['image_id'] = $theirs;

		foreach ( array(
			'another vendor\'s image'     => self::simple() + array( 'images' => array( $mine, $theirs ) ),
			'an image no vendor uploaded' => self::simple() + array( 'images' => array( $site ) ),
			'another vendor\'s variation' => $variable,
		) as $case => $fields ) {
			$this->assertSame( array( 'mercatus_product_invalid', 'images' ), $this->refusal( $this->request( 'POST', '/me/products', $fields, array( 'Idempotency-Key' => wp_generate_uuid4() ) ), 422 ), $case );
		}

		$product = $this->createProduct( array( 'images' => array( $mine ) ) );
		$this->assertSame( array( $mine ), array_column( $product['images'], 'id' ) );
		$this->assertSame( array( 'mercatus_product_invalid', 'images' ), $this->refusal( $this->request( 'PATCH', '/me/products/' . $product['id'], array( 'images' => array( $theirs ) ), array( 'If-Match' => $this->etag( $this->request( 'GET', '/me/products/' . $product['id'] ) ) ) ), 422 ) );
	}

	public function testAStoreTakesOnlyItsVendorsImages(): void {
		$this->vendorWithStore( 'Owner Maps' );
		$theirs = (int) $this->uploadImage()['id'];
		$this->vendorWithStore( 'Other Maps' );
		$mine = (int) $this->uploadImage()['id'];

		foreach ( array( 'logo_id', 'banner_id' ) as $field ) {
			$this->assertSame( array( 'mercatus_store_invalid', $field ), $this->refusal( $this->call( 'PATCH', '/me/store', array( $field => $theirs ), $this->etag( $this->call( 'GET', '/me/store' ) ) ), 422 ) );
		}
		$store = $this->data(
			$this->call(
				'PATCH',
				'/me/store',
				array(
					'logo_id'   => $mine,
					'banner_id' => $mine,
				),
				$this->etag( $this->call( 'GET', '/me/store' ) )
			),
			200
		);
		$this->assertSame( array( $mine, $mine ), array( $store['logo_id'], $store['banner_id'] ) );
	}

	/**
	 * Records uploads elsewhere that take up a vendor's space.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $bytes     Bytes they take.
	 */
	private function fill( int $vendor_id, int $bytes ): void {
		self::kernel()->container()->get( MediaRepository::class )->insert( new Media( 900001, $vendor_id, '2026/11/elsewhere.jpg', '', 'image/jpeg', $bytes, 1, 1, '' ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
	}
}
