<?php
/**
 * The WooCommerce status a product's state gives (CTL-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Domain;

/**
 * WooCommerce shows and sells only published products, so the product is published only while buyers may see
 * it: approved, offer active and store open. Drafts and rejected products are drafts, pending ones pending,
 * anything else hidden but kept private, and trashed ones in the trash.
 */
final class WooStatus {

	/**
	 * The WooCommerce post status.
	 *
	 * @param string $status       Product state.
	 * @param string $offer_status Offer state.
	 * @param bool   $store_open   Whether the seller's store is active and public; always true for the marketplace.
	 */
	public static function for( string $status, string $offer_status, bool $store_open ): string {
		return match ( $status ) {
			Product::DRAFT, Product::REJECTED => 'draft',
			Product::PENDING                  => 'pending',
			Product::APPROVED                 => Offer::ACTIVE === $offer_status && $store_open ? 'publish' : 'private',
			Product::TRASHED                  => 'trash',
			default                           => 'private',
		};
	}

	/**
	 * Whether buyers may see and buy the product.
	 *
	 * @param string $status       Product state.
	 * @param string $offer_status Offer state.
	 * @param bool   $store_open   Whether the seller's store is open.
	 */
	public static function visible( string $status, string $offer_status, bool $store_open ): bool {
		return 'publish' === self::for( $status, $offer_status, $store_open );
	}
}
