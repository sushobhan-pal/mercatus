<?php
/**
 * The email a vendor gets when their product is approved (CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure\Email;

/**
 * Buyers see the product once its vendor has published it and their store is open.
 */
final class ProductApprovedEmail extends ProductEmail {

	public function __construct() {
		parent::__construct( 'mercatus_product_approved', __( 'Product approved', 'mercatus' ), __( 'Sent to the vendor when a moderator approves one of their products.', 'mercatus' ) );
	}

	public function get_default_subject() {
		return __( 'Your product {product_name} was approved', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'Product approved', 'mercatus' );
	}
}
