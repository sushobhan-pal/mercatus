<?php
/**
 * Vendor reinstated (VEN-7).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure\Email;

/**
 * Sent to the vendor when their store is reinstated.
 */
final class VendorReinstatedEmail extends VendorEmail {

	/**
	 * Sets up the email.
	 */
	public function __construct() {
		parent::__construct( 'mercatus_vendor_reinstated', __( 'Vendor reinstated', 'mercatus' ), __( 'Sent to the vendor when their store is reinstated.', 'mercatus' ), true );
	}

	public function get_default_subject() {
		return __( 'Your store {store_name} is back', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'Your store is back', 'mercatus' );
	}
}
