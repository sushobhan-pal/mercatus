<?php
/**
 * core.vendor's WordPress hooks (VEN-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use Mercatus\Core\Contracts\Module\ModuleHooks;

/**
 * Deleting a WordPress user closes their vendor and withdraws a pending application.
 */
final class VendorHooks implements ModuleHooks {

	/**
	 * Creates the hooks.
	 *
	 * @param Vendors $vendors Vendor commands.
	 */
	public function __construct( private Vendors $vendors ) {}

	public function register(): void {
		add_action( 'deleted_user', array( $this, 'userDeleted' ) );
	}

	/**
	 * Closes a deleted user's vendor.
	 *
	 * @param int|string $user_id The deleted user.
	 */
	public function userDeleted( int|string $user_id ): void {
		$this->vendors->close( (int) $user_id );
	}
}
