<?php
/**
 * Vendor suspended (VEN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure\Email;

/**
 * Sent to the vendor, with the reason, when their store is suspended.
 */
final class VendorSuspendedEmail extends VendorEmail {

	/**
	 * Sets up the email.
	 */
	public function __construct() {
		parent::__construct( 'mercatus_vendor_suspended', __( 'Vendor suspended', 'mercatus' ), __( 'Sent to the vendor, with the reason, when their store is suspended.', 'mercatus' ), true );
	}

	public function get_default_subject() {
		return __( 'Your store {store_name} is suspended', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'Your store is suspended', 'mercatus' );
	}
}
