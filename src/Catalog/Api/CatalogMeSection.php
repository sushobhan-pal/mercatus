<?php
/**
 * The vendor's media space in `/me` (IDN-12, CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Api;

use Mercatus\Core\Catalog\Application\MediaDirectory;
use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\MeSectionProvider;

/**
 * The space used, the quota, the largest upload and the accepted types, so the dashboard can say how much room
 * is left before an upload is refused.
 */
final class CatalogMeSection implements MeSectionProvider {

	/**
	 * Creates the section.
	 *
	 * @param MediaDirectory $media Media.
	 */
	public function __construct( private MediaDirectory $media ) {}

	public function id(): string {
		return 'media';
	}

	public function section( ActorIdentity $actor ): ?array {
		return null === $actor->vendorId ? null : $this->media->usage( $actor->vendorId );
	}
}
