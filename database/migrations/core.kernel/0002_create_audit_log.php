<?php
/**
 * Creates the audit log (DATA-2, API-3, KER-9).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

use Mercatus\Core\Contracts\Database\Migration;
use Mercatus\Core\Contracts\Database\MigrationContext;

return new class() implements Migration {

	public function up( MigrationContext $db ): void {
		$db->statement(
			"CREATE TABLE IF NOT EXISTS {$db->table( 'mercatus_audit_log' )} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_type VARCHAR(32) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL,
				action VARCHAR(191) NOT NULL,
				resource_type VARCHAR(64) NOT NULL,
				resource_id VARCHAR(64) NOT NULL,
				request_id VARCHAR(64) NOT NULL,
				changes LONGTEXT NOT NULL,
				created_at DATETIME(3) NOT NULL,
				PRIMARY KEY (id),
				KEY actor_created (actor_id, created_at DESC),
				KEY resource_created (resource_type, resource_id, created_at DESC)
			) {$db->charsetCollate()}"
		);
	}
};
