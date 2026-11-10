<?php
/**
 * The email a vendor gets when their product is rejected (CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure\Email;

/**
 * It carries the moderator's reason, so the vendor can fix the product and publish it again.
 */
final class ProductRejectedEmail extends ProductEmail {

	public function __construct() {
		parent::__construct( 'mercatus_product_rejected', __( 'Product rejected', 'mercatus' ), __( 'Sent to the vendor, with the reason, when a moderator rejects one of their products.', 'mercatus' ) );
	}

	public function get_default_subject() {
		return __( 'Your product {product_name} was not approved', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'About your product', 'mercatus' );
	}
}
