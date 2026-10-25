<?php
/**
 * Creates the fulfilment locations table (DATA-2, CAT-1, CAT-6, STO-4).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_fulfilment_locations' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				vendor_id BIGINT UNSIGNED NOT NULL,
				type VARCHAR(16) NOT NULL,
				is_default TINYINT(1) NOT NULL DEFAULT 0,
				status VARCHAR(16) NOT NULL,
				address_1 VARCHAR(191) NOT NULL DEFAULT '',
				address_2 VARCHAR(191) NOT NULL DEFAULT '',
				city VARCHAR(100) NOT NULL DEFAULT '',
				state VARCHAR(100) NOT NULL DEFAULT '',
				postcode VARCHAR(20) NOT NULL DEFAULT '',
				country CHAR(2) NOT NULL DEFAULT '',
				latitude DECIMAL(10,7) NULL,
				longitude DECIMAL(10,7) NULL,
				geo_cell CHAR(6) NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				KEY vendor_status_default (vendor_id, status, is_default),
				KEY geo_cell_status (geo_cell, status)
			) {$db->charsetCollate()}"
		);
	}
};
