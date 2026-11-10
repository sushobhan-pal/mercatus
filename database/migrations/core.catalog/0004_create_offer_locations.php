<?php
/**
 * Creates the offer locations table: an offer at a fulfilment location (DATA-2, CAT-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_offer_locations' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				vendor_offer_id BIGINT UNSIGNED NOT NULL,
				location_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(16) NOT NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY offer_location (vendor_offer_id, location_id),
				KEY location_status (location_id, status)
			) {$db->charsetCollate()}"
		);
	}
};
