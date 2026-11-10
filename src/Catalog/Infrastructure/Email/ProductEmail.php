<?php
/**
 * A WooCommerce email about one of a vendor's products (CTL-20, ARCH-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure\Email;

use Mercatus\Core\Vendor\Infrastructure\Email\VendorEmail;

/**
 * Sent to the vendor in their own language, like core.vendor's emails; subjects may name the product.
 */
abstract class ProductEmail extends VendorEmail {

	/**
	 * Creates the email.
	 *
	 * @param string $id    Email ID.
	 * @param string $title Title in WooCommerce's email settings.
	 * @param string $about Description there.
	 */
	public function __construct( string $id, string $title, string $about ) {
		parent::__construct( $id, $title, $about, true );
		$this->placeholders['{product_name}'] = '';
	}

	/**
	 * Sends the email to a vendor's user.
	 *
	 * @param int                  $user_id The vendor's user.
	 * @param array<string, mixed> $context store_name, product_name, reason and dashboard_url.
	 */
	public function trigger( int $user_id, array $context ): void {
		$this->placeholders['{product_name}'] = is_string( $context['product_name'] ?? null ) ? $context['product_name'] : '';
		parent::trigger( $user_id, $context );
	}
}
