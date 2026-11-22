<?php
/**
 * Creates the offer variations table: a variable product's prices per variation (DATA-2, CTL-22).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_offer_variations' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				vendor_offer_id BIGINT UNSIGNED NOT NULL,
				variation_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(16) NOT NULL,
				base_price DECIMAL(20,6) NULL,
				sale_price DECIMAL(20,6) NULL,
				sale_starts_at DATETIME(3) NULL,
				sale_ends_at DATETIME(3) NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY offer_variation (vendor_offer_id, variation_id)
			) {$db->charsetCollate()}"
		);
	}
};
