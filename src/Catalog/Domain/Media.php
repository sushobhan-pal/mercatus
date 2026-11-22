<?php
/**
 * A vendor's uploaded image (DATA-2 `media_ownership`, CTL-14, CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

/**
 * Recorded when the vendor uploads it, with what the media list shows, so the list never reads WordPress's
 * tables. Its ID is the attachment's.
 */
final readonly class Media {

	/**
	 * Creates the record.
	 *
	 * @param int    $id        Attachment ID.
	 * @param int    $vendorId  The vendor who uploaded it.
	 * @param string $file      Path relative to the uploads folder.
	 * @param string $thumbnail Its thumbnail's path relative to the uploads folder, or ''.
	 * @param string $mime      MIME type, read from the content.
	 * @param int    $bytes     The image plus the sizes WordPress made of it.
	 * @param int    $width     Width in pixels.
	 * @param int    $height    Height in pixels.
	 * @param string $createdAt Uploaded, UTC.
	 */
	public function __construct(
		public int $id,
		public int $vendorId,
		public string $file,
		public string $thumbnail,
		public string $mime,
		public int $bytes,
		public int $width,
		public int $height,
		public string $createdAt,
	) {}

	/**
	 * The record from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function fromArray( array $row ): self {
		return new self(
			(int) $row['attachment_id'],
			(int) $row['vendor_id'],
			(string) $row['file'],
			(string) $row['thumbnail'],
			(string) $row['mime'],
			(int) $row['bytes'],
			(int) $row['width'],
			(int) $row['height'],
			(string) $row['created_at'],
		);
	}
}
