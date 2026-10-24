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
 * WooCommerce's, and its personal data joins WordPress's export and erase tools (VEN-9). The dashboard page
 * replaces the theme on its page and its translations, and wp-admin gets one link to it (ARCH-13, VEN-15).
 */
final class VendorHooks implements ModuleHooks {

	/**
	 * Creates the hooks.
	 *
	 * @param Vendors       $vendors   Vendor commands.
	 * @param DashboardPage $dashboard The dashboard page.
	 * @param VendorPrivacy $privacy   The personal-data exporter and eraser.
	 */
	public function __construct( private Vendors $vendors, private DashboardPage $dashboard, private VendorPrivacy $privacy ) {}

	public function register(): void {
		add_action( 'deleted_user', array( $this, 'userDeleted' ) );
		add_filter( 'woocommerce_email_classes', array( $this, 'emails' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this->privacy, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this->privacy, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'adminInit' ) );
		add_action( 'admin_menu', array( $this, 'adminMenu' ) );
		add_action( 'template_redirect', array( $this->dashboard, 'redirect' ) );
		// Late, so page builders and themes that swap templates don't take the dashboard over.
		add_filter( 'template_include', array( $this->dashboard, 'template' ), 1000 );
	}

	/**
	 * Creates the dashboard page once, and registers the administrator's questions for translation (VEN-15).
	 */
	public function adminInit(): void {
		$this->dashboard->ensure();
		$this->vendors->registerStrings();
	}

	/**
	 * The one wp-admin link, which opens the dashboard (ARCH-13).
	 */
	public function adminMenu(): void {
		if ( $this->dashboard->id() > 0 ) {
			add_menu_page( __( 'Marketplace', 'mercatus' ), __( 'Marketplace', 'mercatus' ), 'manage_options', $this->dashboard->url(), '', 'dashicons-store', 56 );
		}
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
