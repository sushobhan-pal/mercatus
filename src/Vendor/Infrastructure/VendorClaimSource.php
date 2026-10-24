<?php
/**
 * Who is a vendor owner (IDN-3, VEN-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use Mercatus\Core\Contracts\Identity\ActorClaim;
use Mercatus\Core\Contracts\Identity\ClaimSource;
use Mercatus\Core\Vendor\Domain\VendorLifecycles;

/**
 * One indexed query on a cold identity. Only an active vendor owns a store; applicants and suspended or closed
 * vendors are customers, so tenant routes refuse them.
 */
final class VendorClaimSource implements ClaimSource {

	/**
	 * Creates the source.
	 *
	 * @param VendorStore $vendors Vendors.
	 */
	public function __construct( private VendorStore $vendors ) {}

	public function claims( int $user_id ): array {
		$vendor = $this->vendors->byUserId( $user_id );

		return null !== $vendor && VendorLifecycles::ACTIVE === $vendor->status ? array( ActorClaim::vendorOwner( $vendor->id ) ) : array();
	}
}
