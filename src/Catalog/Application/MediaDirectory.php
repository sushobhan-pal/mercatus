<?php
/**
 * A vendor's media, for the API layer (IDN-21, CTL-14, CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Application;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ListRequest;

/**
 * The port the media endpoints and the `/me` section call. Media is the current vendor's own; anyone else's
 * doesn't exist for them (mercatus_media_unknown).
 */
interface MediaDirectory {

	/**
	 * Uploads an image as the current vendor: checked, saved again without its metadata, and counted against
	 * the vendor's space.
	 *
	 * @param array<string, mixed> $file The upload as WordPress's REST API gives it: name, tmp_name, size, error.
	 * @return array<string, mixed> The media item.
	 * @throws DomainError When the upload failed, is too large, isn't an accepted image, or doesn't fit.
	 */
	public function upload( array $file ): array;

	/**
	 * One page of the current vendor's uploads, newest first.
	 *
	 * @param ListRequest $request Validated list request, sorted by created_at.
	 * @return list<array{id: int, version: int, created_at: string, media: array<string, mixed>}>
	 * @throws DomainError When the actor isn't a vendor.
	 */
	public function mine( ListRequest $request ): array;

	/**
	 * One of the current vendor's uploads.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>
	 * @throws DomainError When it isn't theirs.
	 */
	public function get( int $id ): array;

	/**
	 * Deletes one of the current vendor's uploads, removing it from their products and store, and returns
	 * what it was.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>
	 * @throws DomainError When it isn't theirs.
	 */
	public function delete( int $id ): array;

	/**
	 * A vendor's media space.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array{used_bytes: int, quota_bytes: int, upload_max_bytes: int, types: list<string>}
	 */
	public function usage( int $vendor_id ): array;
}
