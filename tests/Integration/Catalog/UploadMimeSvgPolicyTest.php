<?php
/**
 * What an upload may be (SEC-7, TM-29, CTL-24).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Integration\Catalog;

use Mercatus\Core\Catalog\Infrastructure\ImageSanitizer;
use WP_UnitTestCase;

/**
 * Only images, recognized by their content, never by their name or the type the browser claims. SVG is
 * refused: it's a document that can carry script. An image is saved again without its metadata, so a camera's
 * details or a script hidden in or after it don't survive; a photo is turned upright first; and it's stored
 * with the one extension of what it really is.
 */
final class UploadMimeSvgPolicyTest extends WP_UnitTestCase {

	use CatalogTestHelpers;

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	public function testAnSvgIsRefused(): void {
		$this->vendorWithStore();
		$before = self::attachmentCount();

		$refusal = $this->data( $this->upload( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>', 'logo.svg' ), 415 );

		$this->assertSame( 'mercatus_media_type_refused', $refusal['code'] );
		$this->assertContains( 'image/jpeg', ( (array) $refusal['details'] )['types'] );
		$this->assertNotContains( 'image/svg+xml', ( (array) $refusal['details'] )['types'] );
		$this->assertSame( $before, self::attachmentCount(), 'Nothing was stored.' );
	}

	public function testAScriptIsRefusedWhateverItIsCalled(): void {
		$this->vendorWithStore();
		$before = self::attachmentCount();

		foreach ( array( 'shell.jpg', 'shell.php', 'shell.php.jpg', 'shell.phtml' ) as $name ) {
			$this->assertSame( 'mercatus_media_type_refused', $this->data( $this->upload( "<?php echo 'owned'; ?>", $name ), 415 )['code'], $name );
		}
		$this->assertSame( 'mercatus_media_type_refused', $this->data( $this->upload( 'GIF89a' . pack( 'vv', 1, 1 ) . "<?php echo 'owned'; ?>", 'shell.gif' ), 415 )['code'], 'An image header alone isn\'t an image: it must decode.' );
		$this->assertSame( $before, self::attachmentCount() );
	}

	public function testMetadataAndAnythingHiddenInTheFileAreRemoved(): void {
		$this->vendorWithStore();
		$upload = self::withExif( self::jpeg(), 'Mercatus-camera', 1 ) . "<?php echo 'owned'; ?>";
		$this->assertStringContainsString( 'Mercatus-camera', $upload );

		$image  = $this->uploadImage( $upload, 'holiday.jpg' );
		$stored = self::stored( (int) $image['id'] );

		$this->assertSame( 'image/jpeg', $image['mime'] );
		$this->assertStringNotContainsString( 'Mercatus-camera', $stored, 'The camera\'s details are gone.' );
		$this->assertStringNotContainsString( 'Exif', $stored );
		$this->assertStringNotContainsString( '<?php', $stored, 'So is what was appended.' );
		$this->assertSame( 'image/jpeg', ( (array) getimagesizefromstring( $stored ) )['mime'] ?? null, 'It\'s still the image.' );
	}

	public function testAPhotoIsTurnedUprightBeforeItsOrientationIsDropped(): void {
		$this->vendorWithStore();
		// 40 × 20, red on the left and blue on the right, from a camera held sideways: it shows turned 90° clockwise.
		$image = $this->uploadImage( self::withExif( self::halves(), 'Mercatus-camera', 6 ), 'turned.jpg' );

		$this->assertSame( array( 20, 40 ), array( $image['width'], $image['height'] ) );
		$stored = imagecreatefromstring( self::stored( (int) $image['id'] ) );
		$this->assertNotFalse( $stored );
		$this->assertGreaterThan( 150, ( imagecolorat( $stored, 10, 5 ) >> 16 ) & 0xFF, 'The red half is on top.' );
		$this->assertGreaterThan( 150, imagecolorat( $stored, 10, 35 ) & 0xFF, 'The blue half is below.' );
	}

	public function testAnImageIsStoredAsWhatItIsWithOneExtension(): void {
		$this->vendorWithStore();

		$png = $this->uploadImage( self::png(), 'photo.jpg' );
		$this->assertSame( 'image/png', $png['mime'], 'Its content decides, not its name or the claimed type.' );
		$this->assertStringEndsWith( '.png', (string) get_attached_file( (int) $png['id'] ) );
		$this->assertSame( 'image/png', get_post_mime_type( (int) $png['id'] ) );

		$named = $this->uploadImage( self::jpeg(), 'map.php.jpg' );
		$file  = basename( (string) get_attached_file( (int) $named['id'] ) );
		$this->assertSame( 1, substr_count( $file, '.' ), $file . ': one extension only.' );
		$this->assertStringEndsWith( '.jpg', $file );
	}

	public function testAnImageThatWouldDecodeTooLargeIsRefusedBeforeDecoding(): void {
		$this->vendorWithStore();
		$before = self::attachmentCount();

		$refusal = $this->data( $this->upload( self::pngHeader( 6000, 6000 ), 'huge.png' ), 413 );

		$this->assertSame( array( 'mercatus_media_too_large', ImageSanitizer::MAX_PIXELS ), array( $refusal['code'], ( (array) $refusal['details'] )['max_pixels'] ?? null ) );
		$this->assertSame( $before, self::attachmentCount() );
	}

	/**
	 * A stored image's file.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function stored( int $id ): string {
		return (string) file_get_contents( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
	}

	/**
	 * A JPEG with an EXIF block that names a camera and an orientation.
	 *
	 * @param string $jpeg        JPEG.
	 * @param string $make        Camera maker.
	 * @param int    $orientation EXIF orientation, 1 to 8.
	 */
	private static function withExif( string $jpeg, string $make, int $orientation ): string {
		$make .= "\0";
		// A little-endian TIFF block: its header, then one directory of two entries, Make and Orientation.
		$tiff = 'II' . pack( 'vV', 42, 8 ) . pack( 'v', 2 )
			. pack( 'vvVV', 0x010F, 2, strlen( $make ), 8 + 2 + 2 * 12 + 4 )
			. pack( 'vvVvv', 0x0112, 3, 1, $orientation, 0 )
			. pack( 'V', 0 ) . $make;
		$app1 = "Exif\0\0" . $tiff;

		return substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 . substr( $jpeg, 2 );
	}

	/**
	 * A 40 × 20 JPEG, red on the left and blue on the right.
	 */
	private static function halves(): string {
		$image = imagecreatetruecolor( 40, 20 );
		imagefilledrectangle( $image, 0, 0, 19, 19, (int) imagecolorallocate( $image, 255, 0, 0 ) );
		imagefilledrectangle( $image, 20, 0, 39, 19, (int) imagecolorallocate( $image, 0, 0, 255 ) );
		ob_start();
		imagejpeg( $image, null, 95 );

		return (string) ob_get_clean();
	}

	/**
	 * The start of a PNG that says it's huge: enough for its header to be read, never enough to decode.
	 *
	 * @param int $width  Width it claims.
	 * @param int $height Height it claims.
	 */
	private static function pngHeader( int $width, int $height ): string {
		$header = 'IHDR' . pack( 'NN', $width, $height ) . "\x08\x02\x00\x00\x00";

		return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . $header . pack( 'N', crc32( $header ) ) . pack( 'N', 0 ) . 'IEND' . pack( 'N', crc32( 'IEND' ) );
	}
}
