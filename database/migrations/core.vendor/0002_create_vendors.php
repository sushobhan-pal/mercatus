<?php
/**
 * Creates the vendors table (DATA-2, VEN-1).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_vendors' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				application_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(16) NOT NULL,
				reason TEXT NULL,
				created_at DATETIME(3) NOT NULL,
				updated_at DATETIME(3) NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				UNIQUE KEY user_id (user_id),
				KEY status_created (status, created_at)
			) {$db->charsetCollate()}"
		);
	}
};
