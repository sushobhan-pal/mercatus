<?php
/**
 * New vendor application (VEN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure\Email;

/**
 * Sent to the administrator when someone applies to become a vendor.
 */
final class ApplicationReceivedEmail extends VendorEmail {

	/**
	 * Sets up the email.
	 */
	public function __construct() {
		parent::__construct( 'mercatus_vendor_application_received', __( 'New vendor application', 'mercatus' ), __( 'Sent to the administrator when someone applies to become a vendor.', 'mercatus' ), false );
	}

	public function get_default_subject() {
		return __( '[{site_title}]: New vendor application: {store_name}', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'New vendor application', 'mercatus' );
	}
}
