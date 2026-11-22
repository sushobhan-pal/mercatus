<?php
/**
 * Media ownership for other modules (CTL-14, STO-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Contracts\Media\MediaOwnership;

/**
 * Answers the `MediaOwnership` contract from the media ownership table, so core.store checks a logo or banner
 * without depending on core.catalog.
 */
final class MediaOwners implements MediaOwnership {

	/**
	 * Creates the service.
	 *
	 * @param MediaRepository $media Uploads.
	 */
	public function __construct( private MediaRepository $media ) {}

	public function owns( int $vendor_id, int $attachment_id ): bool {
		return array( $attachment_id ) === $this->media->ownedAmong( $vendor_id, array( $attachment_id ) );
	}
}
