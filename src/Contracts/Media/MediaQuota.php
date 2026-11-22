<?php
/**
 * How much media space a vendor has (SEC-7, CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Contracts\Media;

/**
 * Core gives every vendor the administrator's setting; Pro's Memberships can give each plan its own.
 */
interface MediaQuota {

	/**
	 * A vendor's media space in bytes, generated image sizes included.
	 *
	 * @param int $vendor_id Vendor.
	 */
	public function bytes( int $vendor_id ): int;
}
