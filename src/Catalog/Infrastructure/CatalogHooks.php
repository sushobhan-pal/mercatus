<?php
/**
 * core.catalog's WordPress and WooCommerce hooks (CTL-14, CTL-20, CTL-26).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Catalog\Infrastructure\Email\ProductApprovedEmail;
use Mercatus\Core\Catalog\Infrastructure\Email\ProductRejectedEmail;
use Mercatus\Core\Catalog\Infrastructure\Email\ProductTakenDownEmail;
use Mercatus\Core\Contracts\Module\ModuleHooks;

/**
 * The per-seller SKU rule, the vendor's moderation emails, and forgetting a vendor's image however it's
 * deleted. Outside edits, purchasability and "Sold by" arrive with increment 3.
 */
final class CatalogHooks implements ModuleHooks {

	/**
	 * Creates the hooks.
	 *
	 * @param SkuRule      $skus  The SKU rule.
	 * @param MediaLibrary $media Vendors' images.
	 */
	public function __construct( private SkuRule $skus, private MediaLibrary $media ) {}

	public function register(): void {
		add_filter( 'wc_product_has_unique_sku', array( $this->skus, 'taken' ), 10, 3 );
		add_filter( 'woocommerce_get_product_id_by_sku', array( $this->skus, 'lookup' ), 10, 2 );
		add_filter( 'woocommerce_email_classes', array( $this, 'emails' ) );
		add_action( 'delete_attachment', array( $this, 'attachmentDeleted' ) );
	}

	/**
	 * An attachment is being deleted, from the dashboard or from WordPress's media library (CTL-14).
	 *
	 * @param mixed $post_id Attachment ID.
	 */
	public function attachmentDeleted( mixed $post_id ): void {
		$this->media->forget( (int) $post_id );
	}

	/**
	 * Adds the moderation emails to WooCommerce's (CTL-20, ARCH-12).
	 *
	 * @param array<string, mixed> $emails Email objects by class.
	 * @return array<string, mixed>
	 */
	public function emails( array $emails ): array {
		$emails[ ProductApprovedEmail::class ]  = new ProductApprovedEmail();
		$emails[ ProductRejectedEmail::class ]  = new ProductRejectedEmail();
		$emails[ ProductTakenDownEmail::class ] = new ProductTakenDownEmail();

		return $emails;
	}
}
