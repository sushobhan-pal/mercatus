<?php
/**
 * core.vendor's permission names (VEN-8).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Application;

/**
 * Administrators always pass; managers need these in the shared set (IDN-15).
 */
final class VendorAccess {

	/**
	 * The review queue, approving and rejecting.
	 */
	public const REVIEW = 'vendors.applications.review';

	/**
	 * The vendor list, suspending and reinstating.
	 */
	public const MANAGE = 'vendors.manage';
}
