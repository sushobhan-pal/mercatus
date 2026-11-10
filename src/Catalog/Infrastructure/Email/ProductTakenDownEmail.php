<?php
/**
 * The email a vendor gets when their product is taken down (CTL-20).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure\Email;

/**
 * It carries the moderator's reason; publishing the product again sends it back to review.
 */
final class ProductTakenDownEmail extends ProductEmail {

	public function __construct() {
		parent::__construct( 'mercatus_product_taken_down', __( 'Product taken down', 'mercatus' ), __( 'Sent to the vendor, with the reason, when a moderator takes one of their products down.', 'mercatus' ) );
	}

	public function get_default_subject() {
		return __( 'Your product {product_name} was taken down', 'mercatus' );
	}

	public function get_default_heading() {
		return __( 'About your product', 'mercatus' );
	}
}
