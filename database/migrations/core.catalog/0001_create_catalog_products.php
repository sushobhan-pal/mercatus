<?php
/**
 * Creates the catalog products table (DATA-2, CTL-5).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_catalog_products' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				woo_product_id BIGINT UNSIGNED NOT NULL,
				scope VARCHAR(16) NOT NULL,
				owner_vendor_id BIGINT UNSIGNED NOT NULL,
				product_type VARCHAR(16) NOT NULL,
				status VARCHAR(16) NOT NULL,
				status_reason VARCHAR(500) NOT NULL DEFAULT '',
				primary_category_id BIGINT UNSIGNED NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY woo_product_id (woo_product_id),
				KEY scope_status_type (scope, status, product_type),
				KEY scope_status_id (scope, status, id),
				KEY primary_category_status (primary_category_id, status)
			) {$db->charsetCollate()}"
		);
	}
};
