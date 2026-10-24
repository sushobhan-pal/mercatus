<?php
/**
 * The vendor section of GET /me (IDN-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Api;

use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\MeSectionProvider;
use Mercatus\Core\Vendor\Application\VendorDirectory;

/**
 * Where the actor stands as an applicant or vendor, so the dashboard can show the right screen; nothing for
 * someone who never applied.
 */
final class VendorMeSection implements MeSectionProvider {

	/**
	 * Creates the section.
	 *
	 * @param VendorDirectory $vendors Vendors and applications.
	 */
	public function __construct( private VendorDirectory $vendors ) {}

	public function id(): string {
		return 'vendor';
	}

	public function section( ActorIdentity $actor ): ?array {
		if ( ! $actor->isSignedIn() ) {
			return null;
		}
		$standing = $this->vendors->standing( $actor->userId );

		return null === $standing['vendor'] && null === $standing['application'] ? null : $standing;
	}
}
