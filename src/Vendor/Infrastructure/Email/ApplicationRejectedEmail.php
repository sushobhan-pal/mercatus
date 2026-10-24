<?php
/**
 * Vendor application rejected (VEN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure\Email;

/**
 * Sent to the applicant, with the reason, when their application is rejected.
 */
final class ApplicationRejectedEmail extends VendorEmail {

	/**
	 * Sets up the email.
	 */
	public function __construct() {
		parent::__construct( 'mercatus_vendor_application_rejected', __( 'Vendor application rejected', 'mercatus' ), __( 'Sent to the applicant, with the reason, when their application is rejected.', 'mercatus' ), true );
	}

	public function get_default_subject() {
		return __( 'Your vendor application for {store_name}', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'About your application', 'mercatus' );
	}
}
