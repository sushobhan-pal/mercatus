<?php
/**
 * Creates the inventory balances table, the source of truth for stock (DATA-2, CTL-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_inventory_balances' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				vendor_offer_id BIGINT UNSIGNED NOT NULL,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				location_id BIGINT UNSIGNED NOT NULL,
				manage_stock TINYINT(1) NOT NULL DEFAULT 0,
				quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
				stock_status VARCHAR(16) NOT NULL,
				backorders VARCHAR(8) NOT NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY offer_variation_location (vendor_offer_id, variation_id, location_id)
			) {$db->charsetCollate()}"
		);
	}
};
