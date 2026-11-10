<?php
/**
 * Creates the availability projection every Mercatus list reads (DATA-2, CAT-5, CTL-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_offer_availability' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				vendor_offer_id BIGINT UNSIGNED NOT NULL,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				location_id BIGINT UNSIGNED NOT NULL,
				catalog_product_id BIGINT UNSIGNED NOT NULL,
				vendor_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(16) NOT NULL,
				is_purchasable TINYINT(1) NOT NULL DEFAULT 0,
				effective_price DECIMAL(20,6) NULL,
				min_price DECIMAL(20,6) NULL,
				max_price DECIMAL(20,6) NULL,
				stock_status VARCHAR(16) NOT NULL,
				stock_quantity DECIMAL(20,6) NULL,
				name VARCHAR(191) NOT NULL DEFAULT '',
				sku VARCHAR(100) NOT NULL DEFAULT '',
				card LONGTEXT NOT NULL,
				geo_cell CHAR(6) NULL,
				latitude DECIMAL(10,7) NULL,
				longitude DECIMAL(10,7) NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY offer_variation_location (vendor_offer_id, variation_id, location_id),
				KEY idx_catalog_avail (catalog_product_id, is_purchasable, effective_price),
				KEY idx_geo_avail (geo_cell, is_purchasable, catalog_product_id),
				KEY idx_vendor_avail (vendor_id, is_purchasable, updated_at),
				KEY vendor_products (vendor_id, variation_id, catalog_product_id),
				KEY vendor_status_products (vendor_id, variation_id, status, catalog_product_id),
				KEY sku_vendor (sku, vendor_id)
			) {$db->charsetCollate()}"
		);
	}
};
