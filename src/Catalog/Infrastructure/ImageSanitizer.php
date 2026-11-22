<?php
/**
 * The image policy: which images are accepted, and how they're saved (SEC-7, TM-29, CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use GdImage;
use Imagick;
use Throwable;

/**
 * An image's type is read from its content, never its name: JPEG, PNG, GIF and WebP, plus AVIF where the
 * server can write it; SVG and anything else are refused. Each image is decoded and encoded again, which drops
 * its metadata (camera, GPS location) and anything hidden in or after it; an animated GIF keeps its first frame.
 * A photo is turned upright first, since the orientation its camera recorded is metadata too. GD does the work
 * where it can, since it never carries metadata over; otherwise Imagick strips it.
 */
final class ImageSanitizer {

	/**
	 * Accepted types and the extension each is stored with.
	 */
	public const TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/**
	 * The most pixels an image may have. Decoding takes about 4 bytes a pixel, twice while a photo is turned
	 * upright, so this keeps the work within WordPress's image memory (256 MB); it also refuses a small file
	 * that would decode to gigabytes.
	 */
	public const MAX_PIXELS = 25000000;

	/**
	 * The accepted type of a file, read from its content, or null.
	 *
	 * @param string $path File.
	 */
	public function type( string $path ): ?string {
		$info = is_file( $path ) ? @getimagesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that isn't an image is refused, not reported.
		$mime = is_array( $info ) ? (string) $info['mime'] : '';

		return isset( self::TYPES[ $mime ] ) && $this->writable( $mime ) ? $mime : null;
	}

	/**
	 * An image's pixels, from its header, without decoding it.
	 *
	 * @param string $path File.
	 */
	public function pixels( string $path ): int {
		$info = is_file( $path ) ? @getimagesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that isn't an image is refused, not reported.

		return is_array( $info ) ? $info[0] * $info[1] : 0;
	}

	/**
	 * The accepted types this server can save.
	 *
	 * @return list<string>
	 */
	public function accepted(): array {
		return array_values( array_filter( array_keys( self::TYPES ), array( $this, 'writable' ) ) );
	}

	/**
	 * Decodes an image and encodes it again in place, without its metadata.
	 *
	 * @param string $path File.
	 * @param string $mime Its type, from type().
	 * @return bool Whether it worked; a file that can't be decoded is refused.
	 */
	public function reencode( string $path, string $mime ): bool {
		try {
			$done = self::gd( $mime ) ? $this->withGd( $path, $mime ) : $this->withImagick( $path, $mime );
		} catch ( Throwable ) {
			$done = false;
		}

		return $done && $mime === $this->type( $path );
	}

	/**
	 * Whether GD can read and write a type.
	 *
	 * @param string $mime Type.
	 */
	private static function gd( string $mime ): bool {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagetypes' ) ) {
			return false;
		}

		return match ( $mime ) {
			'image/jpeg' => (bool) ( imagetypes() & IMG_JPG ),
			'image/png'  => (bool) ( imagetypes() & IMG_PNG ),
			'image/gif'  => (bool) ( imagetypes() & IMG_GIF ),
			'image/webp' => (bool) ( imagetypes() & IMG_WEBP ),
			'image/avif' => defined( 'IMG_AVIF' ) && (bool) ( imagetypes() & IMG_AVIF ) && function_exists( 'imageavif' ),
			default      => false,
		};
	}

	/**
	 * Whether this server can save a type.
	 *
	 * @param string $mime Type.
	 */
	private function writable( string $mime ): bool {
		if ( self::gd( $mime ) ) {
			return true;
		}
		if ( ! class_exists( Imagick::class ) ) {
			return false;
		}
		try {
			return array() !== Imagick::queryFormats( strtoupper( self::TYPES[ $mime ] ?? '' ) );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * Encodes again with GD, which reads only the first frame and carries no metadata over.
	 *
	 * @param string $path File.
	 * @param string $mime Type.
	 */
	private function withGd( string $path, string $mime ): bool {
		wp_raise_memory_limit( 'image' );
		$data  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload.
		$image = false === $data ? false : @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an undecodable file is refused.
		$image = false === $image ? false : self::upright( $image, 'image/jpeg' === $mime ? self::orientation( $path ) : 1 );
		if ( false === $image ) {
			return false;
		}
		if ( in_array( $mime, array( 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
		}

		return match ( $mime ) {
			'image/jpeg' => imagejpeg( $image, $path, 90 ),
			'image/png'  => imagepng( $image, $path ),
			'image/gif'  => imagegif( $image, $path ),
			'image/webp' => imagewebp( $image, $path, 90 ),
			'image/avif' => imageavif( $image, $path, 70 ),
			default      => false,
		};
	}

	/**
	 * Encodes again with Imagick: the first frame only, with its profiles and comments stripped.
	 *
	 * @param string $path File.
	 * @param string $mime Type.
	 */
	private function withImagick( string $path, string $mime ): bool {
		if ( ! class_exists( Imagick::class ) ) {
			return false;
		}
		$image = new Imagick();
		$image->readImage( $path . '[0]' );
		match ( $image->getImageOrientation() ) {
			Imagick::ORIENTATION_TOPRIGHT    => $image->flopImage(),
			Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage( '#000', 180 ),
			Imagick::ORIENTATION_BOTTOMLEFT  => $image->flipImage(),
			Imagick::ORIENTATION_LEFTTOP     => $image->transposeImage(),
			Imagick::ORIENTATION_RIGHTTOP    => $image->rotateImage( '#000', 90 ),
			Imagick::ORIENTATION_RIGHTBOTTOM => $image->transverseImage(),
			Imagick::ORIENTATION_LEFTBOTTOM  => $image->rotateImage( '#000', 270 ),
			default                          => true,
		};
		$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
		$image->stripImage();
		$image->setImageFormat( self::TYPES[ $mime ] );
		$written = $image->writeImage( $path );
		$image->clear();

		return $written;
	}

	/**
	 * A JPEG's EXIF orientation, or 1 when it records none.
	 *
	 * @param string $path File.
	 */
	private static function orientation( string $path ): int {
		$exif        = function_exists( 'exif_read_data' ) ? @exif_read_data( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- broken metadata is dropped anyway.
		$orientation = is_array( $exif ) ? ( $exif['Orientation'] ?? 1 ) : 1;

		return is_int( $orientation ) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
	}

	/**
	 * Turns a decoded photo the way its EXIF orientation says, as WordPress does, since the orientation itself
	 * won't survive the encoding.
	 *
	 * @param GdImage $image       Decoded image.
	 * @param int     $orientation EXIF orientation, 1 to 8.
	 */
	private static function upright( GdImage $image, int $orientation ): GdImage|false {
		// imagerotate() turns counter-clockwise.
		$angle = match ( $orientation ) {
			3       => 180,
			5, 7, 8 => 90,
			6       => 270,
			default => 0,
		};
		$image = 0 === $angle ? $image : imagerotate( $image, $angle, 0 );
		$flip  = match ( $orientation ) {
			2, 7    => IMG_FLIP_HORIZONTAL,
			4, 5    => IMG_FLIP_VERTICAL,
			default => 0,
		};

		return false === $image || ( 0 !== $flip && ! imageflip( $image, $flip ) ) ? false : $image;
	}
}
