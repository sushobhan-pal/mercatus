<?php
/**
 * Vendor application approved (VEN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure\Email;

/**
 * Sent to the applicant when their application is approved.
 */
final class ApplicationApprovedEmail extends VendorEmail {

	/**
	 * Sets up the email.
	 */
	public function __construct() {
		parent::__construct( 'mercatus_vendor_application_approved', __( 'Vendor application approved', 'mercatus' ), __( 'Sent to the applicant when their application is approved.', 'mercatus' ), true );
	}

	public function get_default_subject() {
		return __( 'Your store {store_name} is approved', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'Welcome to {site_title}', 'mercatus' );
	}
}
