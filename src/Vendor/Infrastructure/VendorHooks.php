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
 * Deleting a WordPress user closes their vendor and withdraws a pending application; the module's emails join
 * WooCommerce's.
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
		add_filter( 'woocommerce_email_classes', array( $this, 'emails' ) );
	}

	/**
	 * Adds core.vendor's emails to WooCommerce's (VEN-7).
	 *
	 * @param array<string, \WC_Email> $emails Emails by class name.
	 * @return array<string, \WC_Email>
	 */
	public function emails( array $emails ): array {
		foreach ( array( Email\ApplicationReceivedEmail::class, Email\ApplicationApprovedEmail::class, Email\ApplicationRejectedEmail::class, Email\VendorSuspendedEmail::class, Email\VendorReinstatedEmail::class ) as $class_name ) {
			$emails[ $class_name ] = new $class_name();
		}

		return $emails;
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
