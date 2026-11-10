<?php
/**
 * SKUs are unique per seller (CTL-26).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use Mercatus\Core\Catalog\Domain\Product;
use Mercatus\Core\Infrastructure\Database\PreparesQueries;
use wpdb;

/**
 * Answers WooCommerce's uniqueness check for the seller of the product being saved, the marketplace counting
 * as one seller, so vendors who resell the same brands may share manufacturers' SKUs. Where WooCommerce looks
 * a product up by SKU alone, the answer is the marketplace's own product, else the only seller's, else
 * nothing, so an administrator's import never updates a vendor's product by accident.
 */
final class SkuRule {

	use PreparesQueries;

	/**
	 * Creates the rule.
	 *
	 * @param AvailabilityRepository $availability The projection, which holds every SKU with its seller.
	 * @param ProductRepository      $products     Catalog products.
	 * @param WooCatalog             $woo          WooCommerce, for the seller of Mercatus's own writes.
	 * @param wpdb                   $db           WordPress database, for the marketplace's own SKUs.
	 */
	public function __construct( private AvailabilityRepository $availability, private ProductRepository $products, private WooCatalog $woo, private wpdb $db ) {}

	/**
	 * For `wc_product_has_unique_sku`: whether another product of the same seller uses the SKU.
	 *
	 * @param mixed $found      WooCommerce's answer, across the whole site.
	 * @param mixed $product_id Product or variation being saved; 0 when new.
	 * @param mixed $sku        SKU.
	 */
	public function taken( mixed $found, mixed $product_id, mixed $sku ): bool {
		$sku        = is_string( $sku ) ? $sku : '';
		$product_id = is_numeric( $product_id ) ? (int) $product_id : 0;
		if ( '' === $sku ) {
			return (bool) $found;
		}
		$catalog = $this->catalogProduct( $product_id );
		$seller  = $this->woo->seller() ?? ( null === $catalog ? Product::MARKETPLACE : $catalog->ownerVendorId );
		if ( Product::MARKETPLACE !== $seller ) {
			return array() !== array_diff( $this->availability->productsWithSku( $seller, $sku ), array( $catalog->id ?? 0 ) );
		}

		return (bool) $found && $this->marketplaceUses( $sku, $product_id );
	}

	/**
	 * For `woocommerce_get_product_id_by_sku`: the product WooCommerce means by a SKU alone.
	 *
	 * @param mixed $id  WooCommerce's answer.
	 * @param mixed $sku SKU.
	 */
	public function lookup( mixed $id, mixed $sku ): int {
		$id  = is_numeric( $id ) ? (int) $id : 0;
		$sku = is_string( $sku ) ? $sku : '';
		if ( '' === $sku || ( $id > 0 && ! $this->isVendors( $id ) ) ) {
			return $id;
		}
		$own = $this->marketplaceProduct( $sku, 0 );
		if ( null !== $own ) {
			return $own;
		}
		$sellers = $this->availability->sellersWithSku( $sku );
		unset( $sellers[ Product::MARKETPLACE ] );
		if ( 1 === count( $sellers ) ) {
			return (int) reset( $sellers );
		}

		return array() === $sellers ? $id : 0;
	}

	/**
	 * Whether a product of the marketplace's own, not a vendor's, uses a SKU.
	 *
	 * @param string $sku        SKU.
	 * @param int    $product_id Product or variation to leave out.
	 */
	private function marketplaceUses( string $sku, int $product_id ): bool {
		return null !== $this->marketplaceProduct( $sku, $product_id );
	}

	/**
	 * The marketplace's own product or variation that uses a SKU, read from WooCommerce, which holds the
	 * marketplace's products whether or not the catalog has adopted them yet.
	 *
	 * @param string $sku        SKU.
	 * @param int    $product_id Product or variation to leave out, or 0.
	 */
	private function marketplaceProduct( string $sku, int $product_id ): ?int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a read of WooCommerce's SKU meta, only on saves and lookups by SKU.
		$ids = $this->db->get_col( self::prepared( $this->db, "SELECT post_id FROM %i WHERE meta_key = '_sku' AND meta_value = %s AND post_id <> %d ORDER BY post_id LIMIT 20", $this->db->postmeta, $sku, $product_id ) );
		foreach ( $ids as $id ) {
			if ( ! $this->isVendors( (int) $id ) ) {
				return (int) $id;
			}
		}

		return null;
	}

	/**
	 * Whether a product or variation belongs to a vendor's catalog product.
	 *
	 * @param int $id WooCommerce product or variation.
	 */
	private function isVendors( int $id ): bool {
		$catalog = $this->catalogProduct( $id );

		return null !== $catalog && ! $catalog->isMarketplaces();
	}

	/**
	 * The catalog product of a product or of a variation's parent.
	 *
	 * @param int $id WooCommerce product or variation.
	 */
	private function catalogProduct( int $id ): ?Product {
		if ( $id < 1 ) {
			return null;
		}
		$parent = wp_get_post_parent_id( $id );

		return $this->products->byWooId( is_int( $parent ) && $parent > 0 ? $parent : $id );
	}
}
