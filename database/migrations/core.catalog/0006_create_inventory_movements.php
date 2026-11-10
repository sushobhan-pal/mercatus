<?php
/**
 * Creates the append-only stock journal (DATA-2, CTL-6).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_inventory_movements' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				movement_uuid CHAR(36) NOT NULL,
				balance_id BIGINT UNSIGNED NOT NULL,
				delta DECIMAL(20,6) NOT NULL,
				quantity_after DECIMAL(20,6) NOT NULL,
				reason VARCHAR(16) NOT NULL,
				actor_type VARCHAR(32) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME(3) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY movement_uuid (movement_uuid),
				KEY balance_created (balance_id, created_at)
			) {$db->charsetCollate()}"
		);
	}
};
